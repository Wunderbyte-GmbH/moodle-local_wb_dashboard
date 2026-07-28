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
 * Language strings for local_wb_dashboard (Italian).
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activemonth:firstlogin'] = 'Primo accesso nel mese';
$string['activemonth:lastlogin'] = 'Ultimo accesso nel mese';
$string['activemonth:logins'] = 'Accessi nel mese';
$string['activemonth:month'] = 'Mese';
$string['activityprogress:completed'] = 'Attività completate';
$string['activityprogress:completedallexcept'] = 'Tutte le attività completate tranne';
$string['activityprogress:progress'] = 'Percentuale di avanzamento';
$string['activityprogress:remaining'] = 'Attività rimanenti';
$string['activityprogress:trackable'] = 'Attività tracciabili';
$string['cachedef_chartdata'] = 'Dati dei grafici elaborati';
$string['cachedef_filteroptions'] = 'Opzioni dinamiche dei menu dei filtri';
$string['cachedef_pagefilterstate'] = 'Stato dei filtri di pagina per utente';
$string['chart'] = 'Grafico';
$string['chartsettings:colourslot'] = 'Colore {$a}';
$string['chartsettings:gear'] = 'Impostazioni colori';
$string['chartsettings:intro'] = 'Scegli quale colore della tavolozza usa ciascuna posizione. Le posizioni lasciate al valore predefinito continuano a seguire la tavolozza attiva.';
$string['chartsettings:invalidcolour'] = 'Scegli un colore dalla tavolozza.';
$string['chartsettings:paletteoption'] = 'Colore {$a->index} ({$a->hex})';
$string['chartsettings:title'] = 'Colori del grafico';
$string['completedallexcept:activities'] = 'Attività (separate da virgola)';
$string['completedallexcept:activities_help'] = 'Elenco di attività da escludere, separate da virgola, identificate tramite ID del modulo del corso o tramite numero ID. Individua gli utenti che hanno completato tutte le attività con tracciamento del completamento nel corso, ad eccezione di quelle elencate; l\'operatore più restrittivo richiede inoltre che nessuna delle attività elencate sia completata. Vengono mostrate solo le righe dei corsi che contengono almeno una delle attività elencate; gli altri corsi completati interamente non vengono inclusi. Un elenco vuoto disattiva il filtro. Nota che le attività nascoste e le attività con restrizioni di accesso contano comunque come tracciabili, mentre le attività il cui tracciamento del completamento è stato disattivato vengono ignorate del tutto, compresi i completamenti registrati quando era ancora attivo.';
$string['completedallexcept:identifier'] = 'Identifica le attività tramite';
$string['completedallexcept:identifier:cmid'] = 'ID del modulo del corso';
$string['completedallexcept:identifier:idnumber'] = 'Numero ID';
$string['completedallexcept:operator:except'] = 'Ha completato tutto tranne queste';
$string['completedallexcept:operator:exceptnonecomplete'] = 'Ha completato tutto tranne queste, e nessuna di queste è completata';
$string['datasource:activeusers'] = 'Utenti attivi (unici per mese)';
$string['datasource:courseactivityprogress'] = 'Avanzamento del completamento delle attività del corso';
$string['daterangefilter:from'] = 'dal';
$string['daterangefilter:to'] = 'al';
$string['downloadreport:label'] = 'Scarica report';
$string['entity:activemonth'] = 'Mese attivo';
$string['entity:activityprogress'] = 'Avanzamento del completamento delle attività';
$string['error:invalidfieldcombination'] = 'I parametri "valuefields"/"remainderof" richiedono almeno un campo valore e non possono essere combinati con "stackfield" o aggregation=count.';
$string['error:invalidreportid'] = 'ID del report non valido o mancante.';
$string['error:lockedfilterinvalidvalue'] = 'Il valore assegnato al filtro "{$a}" non è un\'opzione valida del filtro del report. Contatta l\'amministratore.';
$string['error:lockedfilternovalue'] = 'Nessun valore ti è stato assegnato per il filtro "{$a}". Contatta l\'amministratore.';
$string['error:missingfilterkey'] = 'Lo shortcode chartfilter richiede un argomento "key".';
$string['error:missingparam'] = 'Parametro obbligatorio "{$a}" mancante.';
$string['error:missingsource'] = 'Lo shortcode chart richiede un argomento "source".';
$string['error:noreportdata'] = 'Il report selezionato non ha restituito dati.';
$string['error:unknownbarmode'] = 'Modalità barra "{$a}" della top list sconosciuta.';
$string['error:unknowncharttype'] = 'Tipo di grafico "{$a}" sconosciuto.';
$string['error:unknowndetailtemplate'] = 'Nessun template di dettaglio denominato "{$a}" è configurato (vedi l\'impostazione di amministrazione "Template di dettaglio").';
$string['error:unknowndisplaymode'] = 'Modalità di visualizzazione digits "{$a}" sconosciuta.';
$string['error:unknowndownloadformat'] = 'Formato di download "{$a}" sconosciuto o disabilitato.';
$string['error:unknownfiltertype'] = 'Tipo di filtro "{$a}" sconosciuto.';
$string['error:unknownsource'] = 'Sorgente dati del grafico "{$a}" sconosciuta.';
$string['label:count'] = 'Conteggio';
$string['label:logged'] = 'Registrato';
$string['label:remaining'] = 'Rimanente';
$string['mapfilter:maplabel'] = 'Mappa cliccabile delle regioni d\'Italia';
$string['mapfilter:noregion'] = 'Nessuna regione selezionata';
$string['pluginname'] = 'Wunderbyte Dashboard Charts';
$string['privacy:metadata'] = 'Il plugin dei grafici dashboard non memorizza dati personali nel database. Le selezioni dei filtri di pagina sono memorizzate solo in cache transitoria.';
$string['settings:activepalette'] = 'Tavolozza attiva';
$string['settings:activepalette_desc'] = 'Il sottoplugin tavolozza usato in tutto il sito. Fornisce lo schema di colori dei grafici e (facoltativamente) il proprio CSS. Installa una tavolozza per aggiungere il branding di un cliente.';
$string['settings:detailtemplates'] = 'Template di dettaglio';
$string['settings:detailtemplates_desc'] = 'Template con nome per la finestra modale "Vedi dettagli" per riga dello shortcode [toplist]. Ogni template inizia con una riga marcatore "=== nome ===" seguita dal suo corpo; definisci in questo unico campo tutti i template di cui hai bisogno. Una toplist li attiva con details=nome e idfield=colonna (la colonna del report contenente l\'id grezzo della riga). Il corpo è HTML arbitrario (div, classi Bootstrap, stili inline) contenente shortcode della dashboard; i segnaposto {{id}} e {{label}} vengono sostituiti con l\'id grezzo e l\'etichetta della riga cliccata. Fissa l\'entità cliccata su ogni shortcode interno con fixedfilters="filterkey:{{id}}" e isolala dai filtri di pagina con consumes=none. Esempio:<br><pre>=== coursedetail ===
&lt;h3&gt;{{label}}&lt;/h3&gt;
[digits source=reportbuilder report=12 valuefield=views consumes=none fixedfilters="courseid:{{id}}"]
[toplist source=reportbuilder report=14 categoryfield=username valuefield=score top=3 consumes=none fixedfilters="courseid:{{id}}"]</pre>Il filtro di testo Shortcodes deve essere attivo affinché il contenuto venga visualizzato. I corpi dei template vengono resi senza pulizia (fiducia nell\'amministratore del sito, come per l\'impostazione dei filtri bloccati). I controlli chartfilter non sono supportati all\'interno dei template di dettaglio.';
$string['settings:lockedfilters'] = 'Filtri bloccati';
$string['settings:lockedfilters_desc'] = 'Blocca le chiavi dei filtri sui campi del profilo utente, una mappatura per riga nel formato "filterkey=nomebrevecampoprofilo" (es. "region=region"). Una mappatura può essere limitata a uno o più ruoli aggiungendo "|ruolo1,ruolo2" (es. "region=region|regionalmanager"): solo gli utenti a cui è assegnato uno di quei ruoli nel contesto di sistema avranno quella chiave bloccata, così ruoli diversi possono avere filtri diversi fissati sulla stessa pagina. Senza suffisso di ruolo, la mappatura si applica a tutti gli utenti. Per un utente interessato, la chiave di filtro mappata viene forzata lato server al valore del campo profilo dell\'utente su tutti i grafici e digits; il controllo [chartfilter] per quella chiave viene sostituito da un valore statico. Il privilegio "local/wb_dashboard:ignorelockedfilters" esenta un utente da tutti i blocchi. Il valore del campo profilo deve corrispondere esattamente ai valori delle opzioni del filtro del report (maiuscole/minuscole e ortografia). Un utente bloccato con un campo profilo vuoto non vede alcun dato. I nomi dei ruoli devono corrispondere a nomi brevi di ruoli esistenti; un nome di ruolo non corrispondente significa che il blocco non si applica a nessuno.';
$string['shortcode:chart'] = 'Visualizza un grafico da una sorgente dati (tipo, sorgente e filtri configurabili).';
$string['shortcode:chartfilter'] = 'Visualizza un controllo filtro a livello di pagina a cui reagiscono tutti i grafici della pagina.';
$string['shortcode:digits'] = 'Visualizza un singolo valore numerico (numero, conteggio o percentuale) come campo stilizzabile.';
$string['shortcode:downloadreport'] = 'Visualizza un pulsante di download per un report personalizzato che esporta con i filtri di pagina correnti applicati.';
$string['shortcode:toplist'] = 'Visualizza una classifica top-N con una barra di avanzamento per riga.';
$string['subplugintype_wbdashboardpalette'] = 'Tavolozza dashboard';
$string['subplugintype_wbdashboardpalette_plural'] = 'Tavolozze dashboard';
$string['toplist:nodata'] = 'Nessun dato per i filtri correnti.';
$string['toplist:seedetails'] = 'Vedi dettagli';
$string['wb_dashboard:configurecharts'] = 'Configurare i colori per singolo grafico';
$string['wb_dashboard:ignorelockedfilters'] = 'Ignorare i filtri di pagina bloccati';
