<?php
/**
 * Ready-to-paste legal clauses for the documents that must be updated
 * (requirement 6): pre-contractual information, general terms, privacy policy.
 *
 * These are practical templates, NOT legal advice. Italian and English are
 * provided in full; other languages fall back to English with a review note.
 * Every clause ends with a reminder to have local counsel review it.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Legal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legal clause provider.
 */
final class ClauseLibrary {

	/**
	 * Clause text keyed by type then language.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const CLAUSES = array(
		'precontractual' => array(
			'it' => 'Diritto di recesso — Hai il diritto di recedere dal contratto entro 14 giorni senza fornire alcuna motivazione. Oltre al modulo di recesso tipo (Allegato I, parte B), puoi esercitare il recesso direttamente online tramite l\'apposita funzione "Recedere dal contratto qui", disponibile nella tua area ordini per tutta la durata del periodo di recesso.',
			'en' => 'Right of withdrawal — You have the right to withdraw from this contract within 14 days without giving any reason. In addition to the model withdrawal form (Annex I, part B), you may exercise your withdrawal directly online using the dedicated "Withdraw from contract here" function, available in your order area throughout the withdrawal period.',
		),
		'terms' => array(
			'it' => 'Modalità di recesso — Il recesso può essere esercitato tramite l\'apposito pulsante di recesso online ("Recedere dal contratto qui") disponibile nell\'area ordini del sito per tutta la durata del periodo di recesso, oppure utilizzando il modulo di recesso tipo (Allegato I, parte B). La conferma del recesso è seguita, senza indebito ritardo, da un avviso di ricevimento su supporto durevole comprensivo del contenuto della dichiarazione e della data e ora di trasmissione. Sono escluse modalità che rendano il recesso più difficile dell\'acquisto.',
			'en' => 'How to withdraw — You may exercise your right of withdrawal through the dedicated online withdrawal button ("Withdraw from contract here") available in the order area of the site throughout the withdrawal period, or by using the model withdrawal form (Annex I, part B). After confirmation, you will receive, without undue delay, an acknowledgement of receipt on a durable medium including the content of your statement and the date and time of its submission. No procedure that makes withdrawal more difficult than the purchase applies.',
		),
		'privacy' => array(
			'it' => 'Registro delle dichiarazioni di recesso — Quando eserciti il diritto di recesso online, registriamo la tua dichiarazione (nome, contratto identificato, indirizzo email per la conferma), l\'indirizzo IP e la data e ora di trasmissione in un archivio immodificabile, ai fini dell\'adempimento di un obbligo legale (art. 6, par. 1, lett. c GDPR) e per finalità probatorie/di accountability (art. 6, par. 1, lett. f GDPR). Questi dati sono conservati per il periodo necessario a tutelare i diritti delle parti (per impostazione predefinita 10 anni). Per le richieste relative ai tuoi dati puoi contattarci agli estremi indicati in questa informativa.',
			'en' => 'Withdrawal log — When you exercise your right of withdrawal online, we record your statement (name, identified contract, email for the confirmation), your IP address and the date and time of submission in a tamper-evident, append-only log, in order to comply with a legal obligation (Art. 6(1)(c) GDPR) and for evidentiary/accountability purposes (Art. 6(1)(f) GDPR). This data is retained for the period necessary to protect the rights of the parties (10 years by default). For requests regarding your data, contact us using the details in this policy.',
		),
		'consent_privacy' => array(
			'it' => 'Prova del consenso alle esenzioni dal recesso — Per i prodotti o servizi per cui la legge esclude il diritto di recesso solo previo tuo consenso espresso (contenuti digitali ad accesso immediato; servizi eseguiti immediatamente), al momento dell\'ordine registriamo il testo del consenso e della presa d\'atto che hai accettato, con data e ora e — salvo tua disattivazione — l\'indirizzo IP, al solo fine di poter dimostrare la validità dell\'esenzione e di esercitare o difendere un diritto in sede giudiziaria. La base giuridica è il legittimo interesse (art. 6, par. 1, lett. f GDPR; cfr. art. 17, par. 3, lett. e GDPR). Conserviamo questi dati per il periodo di prescrizione applicabile (per impostazione predefinita 10 anni), trascorso il quale l\'indirizzo IP viene cancellato/anonimizzato. Puoi opporti al trattamento (art. 21 GDPR), compatibilmente con l\'accertamento o la difesa di un diritto. Questo consenso ai sensi del Codice del Consumo è distinto da un eventuale consenso al trattamento dei dati ai sensi del GDPR.',
			'en' => 'Evidence of withdrawal-exemption consent — For products or services where the law removes the right of withdrawal only with your prior express consent (digital content with immediate access; services performed immediately), at order time we record the exact consent + acknowledgement wording you accepted, with the date and time and — unless you have turned it off — your IP address, for the sole purpose of being able to prove the exemption is valid and to establish, exercise or defend a legal claim. The legal basis is legitimate interest (Art. 6(1)(f) GDPR; see Art. 17(3)(e) GDPR). We keep this data for the applicable limitation period (10 years by default), after which the IP address is deleted/anonymised. You may object to the processing (Art. 21 GDPR), subject to the establishment or defence of legal claims. This consent under consumer law is distinct from any consent to data processing under the GDPR.',
		),
	);

	/**
	 * Disclaimer appended to every clause.
	 *
	 * @var array<string,string>
	 */
	private const DISCLAIMER = array(
		'it' => 'Nota: testo di esempio, da far revisionare dal proprio consulente legale e adattare alla propria attività.',
		'en' => 'Note: sample text — have it reviewed by your own legal counsel and adapt it to your business.',
	);

	/**
	 * Get a clause.
	 *
	 * @param string $type 'precontractual'|'terms'|'privacy'|'consent_privacy'.
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function get( string $type, string $lang ): string {
		$lang = strtolower( substr( $lang, 0, 2 ) );
		if ( ! isset( self::CLAUSES[ $type ] ) ) {
			return '';
		}
		$set       = self::CLAUSES[ $type ];
		$text      = $set[ $lang ] ?? $set['en'];
		$review    = self::DISCLAIMER[ $lang ] ?? self::DISCLAIMER['en'];
		$localised = isset( $set[ $lang ] );

		$prefix = $localised ? '' : '[EN — translate/localise] ';
		return $prefix . $text . "\n\n" . $review;
	}

	/**
	 * Clause types.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return array_keys( self::CLAUSES );
	}
}
