<#
.SYNOPSIS
  Build the distributable plugin ZIP on Windows (PowerShell equivalent of build.sh).

.DESCRIPTION
  - installs production Composer dependencies (Dompdf) into vendor/
  - copies the plugin into build\wwu-withdrawal-button excluding dev files (.distignore)
  - produces dist\wwu-withdrawal-button.zip

.EXAMPLE
  pwsh bin/build.ps1
#>

$ErrorActionPreference = 'Stop'
$Slug     = 'wwu-withdrawal-button'
$Root     = Split-Path -Parent $PSScriptRoot
$BuildDir = Join-Path $Root "build\$Slug"
$DistDir  = Join-Path $Root 'dist'
$Zip      = Join-Path $DistDir "$Slug.zip"

Write-Host '==> Installing production dependencies (Dompdf)...'
Push-Location $Root
composer install --no-dev --optimize-autoloader --no-interaction --no-progress
Pop-Location

Write-Host '==> Preparing build directory...'
if (Test-Path (Join-Path $Root 'build')) { Remove-Item -Recurse -Force (Join-Path $Root 'build') }
if (Test-Path $DistDir) { Remove-Item -Recurse -Force $DistDir }
New-Item -ItemType Directory -Force -Path $BuildDir | Out-Null
New-Item -ItemType Directory -Force -Path $DistDir  | Out-Null

# Read exclude patterns from .distignore (leading slash = top-level path).
$excludes = Get-Content (Join-Path $Root '.distignore') |
	Where-Object { $_ -and ($_ -notmatch '^\s*#') } |
	ForEach-Object { $_.Trim().TrimStart('/') }
$excludes += @('build', 'dist', '.git')

Write-Host '==> Copying plugin files...'
$srcLen = $Root.Length + 1
Get-ChildItem -Path $Root -Recurse -File | ForEach-Object {
	$rel = $_.FullName.Substring($srcLen)
	$top = ($rel -split '[\\/]')[0]
	$name = $_.Name
	$skip = $false
	foreach ($e in $excludes) {
		if ($top -ieq $e -or $name -ieq $e -or ($e -like '*.dist' -and $name -like '*.dist')) { $skip = $true; break }
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
Write-Host "==> Done: dist\$Slug.zip ($size MB)"
