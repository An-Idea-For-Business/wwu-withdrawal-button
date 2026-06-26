<#
.SYNOPSIS
  Build the distributable plugin ZIP on Windows (PowerShell equivalent of build.sh).

.DESCRIPTION
  Single source of truth = this branch (PHP 8.1, Dompdf 3.x).

  Default run:
  - installs production Composer dependencies (Dompdf 3.x) into vendor/
  - copies the plugin into build\wwu-withdrawal-button excluding dev files (.distignore)
  - produces dist\wwu-withdrawal-button.zip

  -Php74 run (derived artifact, no separate branch):
  - temporarily rewrites the 5 known PHP-version deltas in a backup-and-restore
    wrapper (composer.json dompdf ^2.0 + php >=7.4 + no platform pin; the plugin
    header Requires PHP / WEBWAKEUPWDB_MIN_PHP; readme Requires PHP),
  - re-resolves Dompdf to the 2.x line (PHP 7.4-compatible),
  - produces dist\wwu-withdrawal-button-php7.4.zip,
  - ALWAYS restores the PHP 8.1 source + Dompdf 3.x afterwards (try/finally),
  so the working tree is left exactly as it was. Run it right after the default
  build; it removes only its own target zip, so the 8.1 zip is preserved.

.EXAMPLE
  pwsh bin/build.ps1            # PHP 8.1 build  -> dist\wwu-withdrawal-button.zip
  pwsh bin/build.ps1 -Php74     # PHP 7.4 build  -> dist\wwu-withdrawal-button-php7.4.zip
#>

param(
	[switch]$Php74
)

$ErrorActionPreference = 'Stop'
$Slug     = 'wwu-withdrawal-button'
$Root     = Split-Path -Parent $PSScriptRoot
$BuildDir = Join-Path $Root "build\$Slug"
$DistDir  = Join-Path $Root 'dist'
if ($Php74) { $ZipName = "$Slug-php7.4.zip" } else { $ZipName = "$Slug.zip" }
$Zip = Join-Path $DistDir $ZipName

# Files mutated only for the PHP 7.4 derivation; backed up and restored verbatim.
$composerFile = Join-Path $Root 'composer.json'
$lockFile     = Join-Path $Root 'composer.lock'
$bootFile     = Join-Path $Root "$Slug.php"
$readmeFile   = Join-Path $Root 'readme.txt'
$backups      = @{}

function Restore-Source {
	foreach ($f in $script:backups.Keys) {
		if ($null -eq $script:backups[$f]) {
			if (Test-Path $f) { Remove-Item -LiteralPath $f -Force }
		} else {
			Set-Content -LiteralPath $f -Value $script:backups[$f] -NoNewline
		}
	}
}

try {
	if ($Php74) {
		Write-Host '==> [php7.4] Deriving the PHP 7.4 build from the PHP 8.1 source...'
		foreach ($f in @($composerFile, $lockFile, $bootFile, $readmeFile)) {
			if (Test-Path $f) { $backups[$f] = (Get-Content -Raw -LiteralPath $f) } else { $backups[$f] = $null }
		}

		# composer.json -> Dompdf 2.x, php >=7.4, drop the 8.1 platform pin.
		$c = $backups[$composerFile]
		$c = $c -replace '"php":\s*">=8\.1"', '"php": ">=7.4"'
		$c = $c -replace '"dompdf/dompdf":\s*"\^3\.1"', '"dompdf/dompdf": "^2.0"'
		$c = $c -replace '(?s),\s*"platform":\s*\{[^}]*\}', ''
		Set-Content -LiteralPath $composerFile -Value $c -NoNewline

		# Plugin header -> Requires PHP 7.4 + WEBWAKEUPWDB_MIN_PHP 7.4.
		$b = $backups[$bootFile]
		$b = $b -replace 'Requires PHP:(\s+)8\.1', 'Requires PHP:${1}7.4'
		$b = $b -replace "(WEBWAKEUPWDB_MIN_PHP',\s*)'8\.1'", "`${1}'7.4'"
		Set-Content -LiteralPath $bootFile -Value $b -NoNewline

		# readme.txt -> Requires PHP 7.4.
		$r = $backups[$readmeFile]
		$r = $r -replace 'Requires PHP:(\s+)8\.1', 'Requires PHP:${1}7.4'
		Set-Content -LiteralPath $readmeFile -Value $r -NoNewline

		# Drop the 8.1 lock so a full Composer resolve picks the 7.4-compatible 2.x
		# line (a partial `update <pkg>` would require an existing lock).
		if (Test-Path $lockFile) { Remove-Item -LiteralPath $lockFile -Force }
		Write-Host '==> [php7.4] Resolving Dompdf 2.x...'
		Push-Location $Root
		composer update --no-dev --optimize-autoloader --no-interaction --no-progress
		$updateExit = $LASTEXITCODE
		Pop-Location
		if ($updateExit -ne 0) { throw "[php7.4] composer update failed (exit $updateExit) - aborting; the PHP 8.1 source is restored in finally." }
	} else {
		Write-Host '==> Installing production dependencies (Dompdf)...'
		Push-Location $Root
		composer install --no-dev --optimize-autoloader --no-interaction --no-progress
		Pop-Location
	}

	Write-Host '==> Preparing build directory...'
	if (Test-Path (Join-Path $Root 'build')) { Remove-Item -Recurse -Force (Join-Path $Root 'build') }
	New-Item -ItemType Directory -Force -Path $BuildDir | Out-Null
	New-Item -ItemType Directory -Force -Path $DistDir  | Out-Null
	# Remove only this run's target zip so the sibling build (8.1 vs 7.4) is preserved.
	if (Test-Path $Zip) { Remove-Item -Force $Zip }

	# Read exclude patterns from .distignore (leading slash = top-level path).
	$excludes = Get-Content (Join-Path $Root '.distignore') |
		Where-Object { $_ -and ($_ -notmatch '^\s*#') } |
		ForEach-Object { $_.Trim().TrimStart('/') }
	$excludes += @('build', 'dist', '.git')

	Write-Host '==> Copying plugin files...'
	$srcLen = $Root.Length + 1
	Get-ChildItem -Path $Root -Recurse -File | ForEach-Object {
		$rel = $_.FullName.Substring($srcLen)
		$relUnix = $rel -replace '\\', '/'
		$top = ($rel -split '[\\/]')[0]
		$name = $_.Name
		$skip = $false
		foreach ($e in $excludes) {
			$eUnix = ($e -replace '\\', '/').TrimEnd('/')
			# Exclude when the entry matches a top-level dir/file, a bare filename
			# anywhere, an exact nested path, a nested directory prefix, or *.dist.
			if ($top -ieq $e -or $name -ieq $e -or
				$relUnix -ieq $eUnix -or $relUnix -ilike "$eUnix/*" -or
				($e -like '*.dist' -and $name -like '*.dist')) { $skip = $true; break }
		}
		if (-not $skip) {
			$dest = Join-Path $BuildDir $rel
			New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dest) | Out-Null
			Copy-Item $_.FullName -Destination $dest
		}
	}

	Write-Host '==> Zipping...'
	Compress-Archive -Path $BuildDir -DestinationPath $Zip -Force

	$size = [math]::Round((Get-Item $Zip).Length / 1MB, 2)
	Write-Host "==> Done: dist\$ZipName ($size MB)"
}
finally {
	if ($Php74) {
		Write-Host '==> [php7.4] Restoring the PHP 8.1 source + Dompdf 3.x...'
		Restore-Source
		Push-Location $Root
		composer install --no-dev --optimize-autoloader --no-interaction --no-progress
		Pop-Location
	}
}
