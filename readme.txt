=== Connector Lexware Office for WooCommerce ===
Contributors: patsch9
Tags: woocommerce, invoicing, lexware, billing, api
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Dieses Plugin verbindet WooCommerce mit der Lexware Office API und erstellt automatisch Rechnungen aus WooCommerce-Bestellungen.

== Description ==
**Wichtig:** Dies ist ein inoffizielles Plugin und steht in keiner Verbindung zur Haufe-Lexware GmbH & Co. KG. Lexware® ist eine eingetragene Marke der Haufe-Lexware GmbH & Co. KG.

Funktionen:
* Automatische Rechnungserstellung in Lexware
* Kundendaten-Synchronisation
* Queue-basiertes Fehlerhandling
* Integration in den WooCommerce-Kundenbereich
* Wiederholungsversuche bei Übertragungsfehlern
* Automatische E-Mail-Benachrichtigung

Voraussetzungen:
* Aktiver Lexware-Account
* Lexware Office **XL**-Abo oder Testversion (API steht nur in dieser Tarifstufe zur Verfügung)
* Gültiger Lexware API-Key
* Externe Datenübertragung zu Lexware-Servern erforderlich

== Datenschutz ==
Dieses Plugin überträgt Bestell- und Kundendaten an Lexware (lexware.de). Details siehe die Lexware-Datenschutzerklärung.

== Installation ==
1. Plugin hochladen und aktivieren
2. WooCommerce und Lexware-API Key konfigurieren
3. Trigger-Status für die automatische Rechnungserstellung wählen

== Frequently Asked Questions ==
= Benötige ich einen Lexware-Account? =
Ja, ein aktiver Lexware-Account mit API-Zugang ist nötig.

= Welches Lexware-Produkt brauche ich für die API? =
Die Public API ist aktuell nur mit Lexware Office XL oder während der kostenlosen Testphase nutzbar.

= Welche Daten werden übertragen? =
Kunden- und Rechnungsdaten werden an die Lexware-API übermittelt.

== Screenshots ==
1. Einstellungsseite (coming soon)
2. Queue-Übersicht (coming soon)
3. Rechnungsansicht im Kundenbereich (coming soon)

== Changelog ==
= 1.0.0 =
* Erste öffentliche Version
* Automatische Rechnungserstellung
* Kundensynchronisation
* Fehlerbehandlung

== Upgrade Notice ==
Erstes Release. Änderungen siehe Changelog.
