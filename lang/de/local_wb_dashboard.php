<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Language strings for local_wb_dashboard (German).
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activemonth:firstlogin'] = 'Erster Login im Monat';
$string['activemonth:lastlogin'] = 'Letzter Login im Monat';
$string['activemonth:logins'] = 'Logins im Monat';
$string['activemonth:month'] = 'Monat';
$string['activityprogress:completed'] = 'Abgeschlossene Aktivitäten';
$string['activityprogress:completedallexcept'] = 'Alle Aktivitäten abgeschlossen außer';
$string['activityprogress:progress'] = 'Fortschritt in Prozent';
$string['activityprogress:remaining'] = 'Verbleibende Aktivitäten';
$string['activityprogress:trackable'] = 'Verfolgbare Aktivitäten';
$string['cachedef_chartdata'] = 'Aufbereitete Diagrammdaten';
$string['cachedef_filteroptions'] = 'Dynamische Filter-Auswahloptionen';
$string['cachedef_pagefilterstate'] = 'Seitenfilter-Status pro Nutzer/in';
$string['chart'] = 'Diagramm';
$string['chartsettings:colourslot'] = 'Farbe {$a}';
$string['chartsettings:gear'] = 'Farbeinstellungen';
$string['chartsettings:intro'] = 'Wählen Sie, welche Palettenfarbe jeder Platz verwendet. Plätze, die auf ihrem Standard belassen werden, folgen weiterhin der aktiven Palette.';
$string['chartsettings:invalidcolour'] = 'Wählen Sie eine Farbe aus der Palette.';
$string['chartsettings:paletteoption'] = 'Farbe {$a->index} ({$a->hex})';
$string['chartsettings:title'] = 'Diagrammfarben';
$string['completedallexcept:activities'] = 'Aktivitäten (kommagetrennt)';
$string['completedallexcept:activities_help'] = 'Kommagetrennte Liste der auszuschließenden Aktivitäten, identifiziert über die Kursmodul-ID oder die ID-Nummer. Erfasst Nutzer/innen, die jede Aktivität mit Abschlussverfolgung im Kurs abgeschlossen haben, mit Ausnahme der aufgelisteten; der strengere Operator verlangt zusätzlich, dass keine der aufgelisteten Aktivitäten abgeschlossen ist. Es werden nur Zeilen von Kursen angezeigt, die mindestens eine der aufgelisteten Aktivitäten enthalten; andere vollständig abgeschlossene Kurse werden nicht erfasst. Eine leere Liste deaktiviert den Filter. Beachten Sie, dass verborgene Aktivitäten und Aktivitäten mit Zugriffsbeschränkungen weiterhin als verfolgbar zählen und dass Aktivitäten, deren Abschlussverfolgung abgeschaltet wurde, vollständig ignoriert werden, einschließlich aller Abschlüsse, die erfasst wurden, während sie noch aktiv war.';
$string['completedallexcept:identifier'] = 'Aktivitäten identifizieren über';
$string['completedallexcept:identifier:cmid'] = 'Kursmodul-ID';
$string['completedallexcept:identifier:idnumber'] = 'ID-Nummer';
$string['completedallexcept:operator:except'] = 'Alles abgeschlossen außer diesen';
$string['completedallexcept:operator:exceptnonecomplete'] = 'Alles abgeschlossen außer diesen, und keine davon ist abgeschlossen';
$string['datasource:activeusers'] = 'Aktive Nutzer/innen (eindeutig pro Monat)';
$string['datasource:courseactivityprogress'] = 'Fortschritt beim Aktivitätsabschluss im Kurs';
$string['daterangefilter:from'] = 'von';
$string['daterangefilter:to'] = 'bis';
$string['downloadreport:label'] = 'Bericht herunterladen';
$string['entity:activemonth'] = 'Aktiver Monat';
$string['entity:activityprogress'] = 'Fortschritt beim Aktivitätsabschluss';
$string['error:invalidfieldcombination'] = 'Die Parameter "valuefields"/"remainderof" benötigen mindestens ein Wertefeld und können nicht mit "stackfield" oder aggregation=count kombiniert werden.';
$string['error:invalidreportid'] = 'Ungültige oder fehlende Berichts-ID.';
$string['error:lockedfilterinvalidvalue'] = 'Der Ihnen zugewiesene Wert für den Filter "{$a}" ist keine gültige Option des Berichtsfilters. Bitte wenden Sie sich an Ihre Administration.';
$string['error:lockedfilternovalue'] = 'Für den Filter "{$a}" ist Ihnen kein Wert zugewiesen. Bitte wenden Sie sich an Ihre Administration.';
$string['error:missingfilterkey'] = 'Der Shortcode chartfilter benötigt ein Argument "key".';
$string['error:missingparam'] = 'Erforderlicher Parameter "{$a}" fehlt.';
$string['error:missingsource'] = 'Der Shortcode chart benötigt ein Argument "source".';
$string['error:noreportdata'] = 'Der ausgewählte Bericht hat keine Daten zurückgegeben.';
$string['error:unknownbarmode'] = 'Unbekannter Balkenmodus "{$a}" für die Topliste.';
$string['error:unknowncharttype'] = 'Unbekannter Diagrammtyp "{$a}".';
$string['error:unknowndetailtemplate'] = 'Es ist kein Detail-Template mit dem Namen "{$a}" konfiguriert (siehe die Administrationseinstellung "Detail-Templates").';
$string['error:unknowndisplaymode'] = 'Unbekannter Anzeigemodus "{$a}" für digits.';
$string['error:unknowndownloadformat'] = 'Unbekanntes oder deaktiviertes Downloadformat "{$a}".';
$string['error:unknownfiltertype'] = 'Unbekannter Filtertyp "{$a}".';
$string['error:unknownsource'] = 'Unbekannte Diagramm-Datenquelle "{$a}".';
$string['label:count'] = 'Anzahl';
$string['label:logged'] = 'Erfasst';
$string['label:remaining'] = 'Verbleibend';
$string['mapfilter:maplabel'] = 'Anklickbare Karte der Regionen Italiens';
$string['mapfilter:noregion'] = 'Keine Region ausgewählt';
$string['pluginname'] = 'Wunderbyte Dashboard Charts';
$string['privacy:metadata'] = 'Das Dashboard-Charts-Plugin speichert keine personenbezogenen Daten in der Datenbank. Seitenfilter-Auswahlen werden nur transient zwischengespeichert.';
$string['settings:activepalette'] = 'Aktive Palette';
$string['settings:activepalette_desc'] = 'Das websiteweit verwendete Paletten-Subplugin. Es liefert das Farbschema der Diagramme und (optional) eigenes CSS. Installieren Sie eine Palette, um das Branding eines Kunden hinzuzufügen.';
$string['settings:detailtemplates'] = 'Detail-Templates';
$string['settings:detailtemplates_desc'] = 'Benannte Templates für das "Details anzeigen"-Modal pro Zeile des Shortcodes [toplist]. Jedes Template beginnt mit einer Markierungszeile "=== name ===", gefolgt von seinem Inhalt; definieren Sie in diesem einen Feld beliebig viele Templates. Eine Topliste aktiviert dies mit details=name und idfield=spalte (die Berichtsspalte mit der Roh-ID der Zeile). Der Inhalt ist beliebiges HTML (divs, Bootstrap-Klassen, Inline-Styles) mit Dashboard-Shortcodes; die Platzhalter {{id}} und {{label}} werden durch die Roh-ID und die Beschriftung der angeklickten Zeile ersetzt. Fixieren Sie die angeklickte Entität auf jedem inneren Shortcode mit fixedfilters="filterkey:{{id}}" und isolieren Sie sie von den Seitenfiltern mit consumes=none. Beispiel:<br><pre>=== coursedetail ===
&lt;h3&gt;{{label}}&lt;/h3&gt;
[digits source=reportbuilder report=12 valuefield=views consumes=none fixedfilters="courseid:{{id}}"]
[toplist source=reportbuilder report=14 categoryfield=username valuefield=score top=3 consumes=none fixedfilters="courseid:{{id}}"]</pre>Der Textfilter "Shortcodes" muss aktiviert sein, damit der Inhalt gerendert wird. Template-Inhalte werden ohne Bereinigung gerendert (Vertrauen in die Website-Administration, wie bei der Einstellung für gesperrte Filter). Chartfilter-Steuerelemente werden innerhalb von Detail-Templates nicht unterstützt.';
$string['settings:lockedfilters'] = 'Gesperrte Filter';
$string['settings:lockedfilters_desc'] = 'Sperren Sie Filterschlüssel auf Nutzerprofilfelder, eine Zuordnung pro Zeile als "filterkey=profilfeldkurzname" (z. B. "region=region"). Eine Zuordnung kann durch Anhängen von "|rolle1,rolle2" auf eine oder mehrere Rollen beschränkt werden (z. B. "region=region|regionalmanager"): Nur Nutzer/innen, denen eine dieser Rollen im Systemkontext zugewiesen ist, erhalten diesen Schlüssel gesperrt, sodass für verschiedene Rollen unterschiedliche Filter auf derselben Seite fixiert sein können. Ohne Rollensuffix gilt die Zuordnung für alle Nutzer/innen. Für betroffene Nutzer/innen wird ein zugeordneter Filterschlüssel serverseitig auf den Wert des eigenen Profilfelds für alle Diagramme und digits erzwungen; das [chartfilter]-Steuerelement für den Schlüssel wird durch einen statischen Wert ersetzt. Die Fähigkeit "local/wb_dashboard:ignorelockedfilters" nimmt eine/n Nutzer/in von allen Sperren aus. Der Profilfeldwert muss exakt mit den Optionswerten des Berichtsfilters übereinstimmen (Groß-/Kleinschreibung und Schreibweise). Gesperrte Nutzer/innen mit leerem Profilfeld sehen keine Daten. Rollennamen müssen vorhandenen Rollenkurznamen entsprechen; ein nicht übereinstimmender Rollenname bedeutet, dass die Sperre für niemanden gilt.';
$string['shortcode:chart'] = 'Rendert ein Diagramm aus einer Datenquelle (Typ, Quelle und Filter konfigurierbar).';
$string['shortcode:chartfilter'] = 'Rendert ein Filter-Steuerelement auf Seitenebene, auf das alle Diagramme der Seite reagieren.';
$string['shortcode:digits'] = 'Rendert einen einzelnen numerischen Wert (Zahl, Anzahl oder Prozentsatz) als gestaltbares Feld.';
$string['shortcode:downloadreport'] = 'Rendert einen Download-Button für einen benutzerdefinierten Bericht, der mit den aktuell angewendeten Seitenfiltern exportiert.';
$string['shortcode:toplist'] = 'Rendert eine sortierte Top-N-Liste mit einem Fortschrittsbalken pro Zeile.';
$string['subplugintype_wbdashboardpalette'] = 'Dashboard-Palette';
$string['subplugintype_wbdashboardpalette_plural'] = 'Dashboard-Paletten';
$string['toplist:nodata'] = 'Keine Daten für die aktuellen Filter.';
$string['toplist:seedetails'] = 'Details anzeigen';
$string['wb_dashboard:configurecharts'] = 'Farben pro Diagramm konfigurieren';
$string['wb_dashboard:ignorelockedfilters'] = 'Gesperrte Seitenfilter ignorieren';
