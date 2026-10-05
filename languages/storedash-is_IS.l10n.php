<?php
/**
 * Icelandic translations (WordPress 6.5+ PHP translation file).
 *
 * Covers the shopper- and merchant-facing gift card strings. Loaded by
 * load_plugin_textdomain() from this folder; WordPress versions before 6.5
 * ignore it and show English.
 *
 * @package StoreDash
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'domain'       => 'storedash',
	'plural-forms' => 'nplurals=2; plural=(n % 10 != 1 || n % 100 == 11);',
	'language'     => 'is_IS',
	'messages'     => array(
		'Gift card'                                      => 'Gjafabréf',
		'Gift card ····%s'                               => 'Gjafabréf ····%s',
		'Gift card code'                                 => 'Kóði gjafabréfs',
		'Apply'                                          => 'Nota',
		'Remove'                                         => 'Fjarlægja',
		'····%1$s: %2$s applied'                         => '····%1$s: %2$s notað',
		'····%1$s: %2$s applied (balance %3$s)'          => '····%1$s: %2$s notað (inneign %3$s)',
		'This gift card code is not valid.'              => 'Þessi gjafabréfskóði er ekki gildur.',
		'Too many attempts. Please wait a few minutes and try again.' => 'Of margar tilraunir. Bíddu í nokkrar mínútur og reyndu aftur.',
		'Gift cards cannot be used to buy gift cards.'   => 'Ekki er hægt að nota gjafabréf til að kaupa gjafabréf.',
		'You can use at most %d gift cards per order.'   => 'Þú getur notað að hámarki %d gjafabréf í hverri pöntun.',
		'Gift cards are not available right now.'        => 'Gjafabréf eru ekki í boði eins og er.',
		'Your gift card balance has changed. Please re-enter your gift card and try again.' => 'Inneign gjafabréfsins hefur breyst. Sláðu kóðann inn aftur og reyndu á ný.',
		'Recipient email (optional)'                     => 'Netfang viðtakanda (valfrjálst)',
		'Leave empty to receive the gift card yourself.' => 'Skildu eftir autt til að fá gjafabréfið sjálf/ur.',
		'Recipient name (optional)'                      => 'Nafn viðtakanda (valfrjálst)',
		'From (optional)'                                => 'Frá (valfrjálst)',
		'Message (optional)'                             => 'Kveðja (valfrjálst)',
		'Send on (optional)'                             => 'Senda dags. (valfrjálst)',
		'Gift card recipient'                            => 'Viðtakandi gjafabréfs',
		'Recipient name'                                 => 'Nafn viðtakanda',
		'From'                                           => 'Frá',
		'Message'                                        => 'Kveðja',
		'Send on'                                        => 'Senda dags.',
		'Please enter a valid recipient email address.'  => 'Sláðu inn gilt netfang viðtakanda.',
		'The gift card message can be at most %d characters.' => 'Kveðjan má vera að hámarki %d stafir.',
		'Please choose a send date between today and one year from now.' => 'Veldu sendingardag frá deginum í dag og allt að ári fram í tímann.',
		'Issued gift card ····%1$s (%2$s).'              => 'Gjafabréf ····%1$s gefið út (%2$s).',
		'Paid %1$s with gift card ····%2$s.'             => 'Greitt %1$s með gjafabréfi ····%2$s.',
		'Released %1$s back to gift card ····%2$s.'      => '%1$s skilað aftur á gjafabréf ····%2$s.',
		'Refunded %1$s to gift card ····%2$s.'           => '%1$s endurgreitt á gjafabréf ····%2$s.',
		'Removed %1$s from gift card ····%2$s after refund.' => '%1$s tekið af gjafabréfi ····%2$s eftir endurgreiðslu.',
		'Gift card purchase refunded (refund #%d).'      => 'Kaup á gjafabréfi endurgreidd (endurgreiðsla #%d).',
		'Gift card payment could not be reserved: the card balance changed. The gift card was removed from this order.' => 'Ekki tókst að taka frá greiðslu af gjafabréfi: inneignin hafði breyst. Gjafabréfið var fjarlægt úr pöntuninni.',
		'Gift cards were not issued because gift cards are turned off in Storedash. Issue them by hand from Storedash if needed.' => 'Gjafabréf voru ekki gefin út því slökkt er á gjafabréfum í Storedash. Gefðu þau út handvirkt í Storedash ef þarf.',
		'Gift card refund: %s had already been spent from the refunded gift card(s) and could not be taken back. Please check this refund.' => 'Endurgreiðsla gjafabréfs: búið var að nota %s af endurgreiddu gjafabréfi/gjafabréfum og ekki var hægt að taka það til baka. Vinsamlegast yfirfarðu endurgreiðsluna.',
		'This order contains gift cards, but the refund did not include the gift card lines, so no gift card balance was changed. Adjust the cards in Storedash if needed.' => 'Pöntunin inniheldur gjafabréf en endurgreiðslan náði ekki til gjafabréfalínanna, svo inneign gjafabréfa var ekki breytt. Leiðréttu gjafabréfin í Storedash ef þarf.',
		'Gift card could not be charged again, so the order total is now %s and has not been paid. Collect the payment before shipping.' => 'Ekki tókst að skuldfæra gjafabréfið aftur, svo heildarupphæð pöntunar er nú %s og hún er ógreidd. Innheimtu greiðsluna áður en pöntunin er send.',
		'Sell this product as a Storedash gift card. Each unit bought issues its own code worth the price paid. Gift cards are virtual and not taxed at sale (VAT is charged when the card is used).' => 'Selja þessa vöru sem Storedash gjafabréf. Hvert keypt eintak fær eigin kóða að verðmæti kaupverðsins. Gjafabréf eru rafræn og ekki skattlögð við sölu (VSK er innheimtur þegar gjafabréfið er notað).',
	),
);
