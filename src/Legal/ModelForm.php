<?php
/**
 * Annex I(B) / Allegato I parte B — model withdrawal form.
 *
 * This standard form remains MANDATORY in pre-contractual information and
 * COEXISTS with the digital withdrawal button (recital 37). The text is the
 * official model form from Directive 2011/83/EU Annex I(B), per language.
 *
 * @see docs/legal/webwakeupwdb-legal-reference.md §3
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Legal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model withdrawal form provider.
 */
final class ModelForm {

	/**
	 * The model form text per language (official Annex I(B) wording).
	 *
	 * @var array<string,array<string,string>>
	 */
	private const FORM = array(
		'it' => array(
			'title'   => 'Modulo di recesso tipo',
			'note'    => '(compilare e restituire il presente modulo solo se si desidera recedere dal contratto)',
			'to'      => 'Destinatario [il professionista inserisce qui il proprio nome, indirizzo geografico e, se disponibile, indirizzo di posta elettronica]:',
			'body'    => 'Con la presente io/noi (*) notifichiamo il recesso dal mio/nostro (*) contratto di vendita dei seguenti beni/servizi (*):',
			'ordered' => 'Ordinato il (*)/ricevuto il (*):',
			'name'    => 'Nome del/dei consumatore(i):',
			'address' => 'Indirizzo del/dei consumatore(i):',
			'sign'    => 'Firma del/dei consumatore(i) (solo se il presente modulo è notificato in versione cartacea):',
			'date'    => 'Data:',
			'delete'  => '(*) Cancellare la dicitura inutile.',
		),
		'en' => array(
			'title'   => 'Model withdrawal form',
			'note'    => '(complete and return this form only if you wish to withdraw from the contract)',
			'to'      => 'To [the trader inserts here his name, geographical address and, where available, his e-mail address]:',
			'body'    => 'I/We (*) hereby give notice that I/We (*) withdraw from my/our (*) contract of sale of the following goods/for the provision of the following service (*):',
			'ordered' => 'Ordered on (*)/received on (*):',
			'name'    => 'Name of consumer(s):',
			'address' => 'Address of consumer(s):',
			'sign'    => 'Signature of consumer(s) (only if this form is notified on paper):',
			'date'    => 'Date:',
			'delete'  => '(*) Delete as appropriate.',
		),
		'de' => array(
			'title'   => 'Muster-Widerrufsformular',
			'note'    => '(Wenn Sie den Vertrag widerrufen wollen, dann füllen Sie bitte dieses Formular aus und senden Sie es zurück.)',
			'to'      => 'An [Name, Anschrift und gegebenenfalls E-Mail-Adresse des Unternehmers sind hier vom Unternehmer einzufügen]:',
			'body'    => 'Hiermit widerrufe(n) ich/wir (*) den von mir/uns (*) abgeschlossenen Vertrag über den Kauf der folgenden Waren/die Erbringung der folgenden Dienstleistung (*):',
			'ordered' => 'Bestellt am (*)/erhalten am (*):',
			'name'    => 'Name des/der Verbraucher(s):',
			'address' => 'Anschrift des/der Verbraucher(s):',
			'sign'    => 'Unterschrift des/der Verbraucher(s) (nur bei Mitteilung auf Papier):',
			'date'    => 'Datum:',
			'delete'  => '(*) Unzutreffendes streichen.',
		),
		'fr' => array(
			'title'   => 'Formulaire type de rétractation',
			'note'    => '(veuillez compléter et renvoyer le présent formulaire uniquement si vous souhaitez vous rétracter du contrat)',
			'to'      => 'À l\'attention de [le professionnel insère ici son nom, son adresse géographique et, lorsqu\'elle est disponible, son adresse électronique] :',
			'body'    => 'Je/nous (*) vous notifie/notifions (*) par la présente ma/notre (*) rétractation du contrat portant sur la vente des biens/la prestation de services (*) ci-dessous :',
			'ordered' => 'Commandé le (*)/reçu le (*) :',
			'name'    => 'Nom du/des consommateur(s) :',
			'address' => 'Adresse du/des consommateur(s) :',
			'sign'    => 'Signature du/des consommateur(s) (uniquement en cas de notification du présent formulaire sur papier) :',
			'date'    => 'Date :',
			'delete'  => '(*) Rayez la mention inutile.',
		),
		'es' => array(
			'title'   => 'Modelo de formulario de desistimiento',
			'note'    => '(sólo debe cumplimentar y enviar el presente formulario si desea desistir del contrato)',
			'to'      => 'A la atención de [el comerciante deberá insertar aquí su nombre, dirección geográfica y, cuando disponga de ella, su dirección de correo electrónico]:',
			'body'    => 'Por la presente le comunico/comunicamos (*) que desisto de mi/desistimos de nuestro (*) contrato de venta de los siguientes bienes/prestación del siguiente servicio (*):',
			'ordered' => 'Pedido el (*)/recibido el (*):',
			'name'    => 'Nombre del consumidor o consumidores:',
			'address' => 'Dirección del consumidor o consumidores:',
			'sign'    => 'Firma del consumidor o consumidores (sólo si el presente formulario se presenta en papel):',
			'date'    => 'Fecha:',
			'delete'  => '(*) Táchese lo que no proceda.',
		),
	);

	/**
	 * Get the model-form strings for a language (falls back to English).
	 *
	 * @param string $lang Language code (it/en/de/fr/es).
	 * @return array<string,string>
	 */
	public static function for_language( string $lang ): array {
		$lang = strtolower( substr( $lang, 0, 2 ) );
		return self::FORM[ $lang ] ?? self::FORM['en'];
	}

	/**
	 * Available language codes.
	 *
	 * @return string[]
	 */
	public static function languages(): array {
		return array_keys( self::FORM );
	}
}
