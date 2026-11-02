# TreeView für IP-Symcon

**Version:** 1.1 · **Status:** Stable (Build 18) · **Modulpräfix:** `TREEV` · **Hersteller:** Cassanis Automation

TreeView ist eine universelle HTML-Kachel zur Darstellung und Bedienung beliebig tief verschachtelter Hierarchien. Die Baumstruktur ist unabhängig vom IP-Symcon-Objektbaum und kann entweder im Konfigurationsformular oder über PHP aufgebaut werden.

## Funktionen

- Nativer Symcon-Baumeditor: Knoten per Drag & Drop umsortieren oder mitsamt Unterbaum zu einem anderen Parent verschieben.
- Drei Darstellungen für Ein-/Ausklappen: Box mit Verbindungslinien, Plus/Minus und Chevron.
- Optionaler Variablenwert, Icon, Hintergrundfarbe und Navigationsziel je Knoten.
- Klick auf einen Knoten aktualisiert die globale Auswahlvariable. Verweist `TargetID` auf ein Symcon-Objekt, öffnet die Kachel dieses auf dem aktuellen Client; verweist es auf ein Script, wird das Script bei einem Benutzerklick ausgeführt (auch bei Link-Knoten). Programmatische Auswahl führt keine Scripts aus.
- Aufgeklappte Zweige und visuelle Auswahl werden pro Browser-Tab und Instanz in `sessionStorage` gehalten; ein Wechsel in die Detailansicht und zurück erhält den Zustand, soweit die Visualisierungsumgebung den Speicher beibehält.
- Lauffähig ab Symcon 8.1; Fullscreen-Unterstützung über das Symcon HTML-SDK ab Symcon 9.1.
- Symcon-Kit-Icons und Font-Awesome-Icons werden automatisch passend dargestellt.
- Konfigurierbares automatisches Zuklappen: Aus, Nur bei Auswahl, Immer.
- Laufzeit-API zum Lesen, Ändern, Verschieben, Einfügen und Löschen von Knoten; Änderungen lassen sich speichern oder verwerfen.

## Konfiguration

Im Feld **Knoten** den Baum aufbauen. Die `ID` muss innerhalb desselben Parents eindeutig sein; unter unterschiedlichen Parents darf dieselbe `ID` erneut vorkommen. Ein Knoten wird deshalb über den vollständigen `IDPath` angesprochen, z. B. `['heating', 'primary', 'vorlauf']`.

| Feld | Bedeutung |
| --- | --- |
| `ID` | Technische ID des Knotens, eindeutig unter Geschwistern |
| `Name` | Angezeigter Text |
| `VariableID` | Optional: aktuelle, formatierte Variable anzeigen; `1` bedeutet keine Wertanzeige |
| `Icon` | Optionales Icon; ein explizites Icon hat Vorrang vor dem Variablen-Icon |
| `Background` | Optionaler RGB-Farbwert; `-1` bedeutet keine eigene Hintergrundfarbe |
| `TargetID` | Optionales Navigationsziel; `1` bedeutet keine Navigation |

Bei allen Objekt-Auswahlfeldern steht `1` für «Kein(e)». Frühere Werte `-1` und `0` werden bei der Migration auf `1` normalisiert; `Background = -1` bleibt davon unberührt.

`VariableID` und `TargetID` sind unabhängig. Für einen aktuellen Wert **und** eine Detailansicht kann dieselbe Variable in beiden Feldern stehen. Bei einer archivierten Variablen kann die Symcon-Detailansicht auch den Archivgraphen anbieten.

## Auswahl und mehrere Clients

Die automatisch angelegte Stringvariable **Selection** enthält nach einem Klick ein JSON-Objekt mit `IDPath` und `Node`. Ohne Auswahl enthält sie `[]`. Sie beschreibt **die letzte globale Auswahl aller Clients** und ist deshalb keine clientbezogene Auswahlvariable. Die grüne Markierung und die geöffneten Zweige sind dagegen clientbezogen.

Die Auswahl bleibt bei Baumänderungen, `TREEV_Save()`, `TREEV_ClearRuntimeData()` und beim Übernehmen der Instanzkonfiguration erhalten, solange der ausgewählte Knoten existiert. Wird er (oder ein übergeordneter Knoten) gelöscht, wird die Auswahl auf `[]` zurückgesetzt. `TREEV_Reset()` löscht die Auswahl immer.

### Wirkung auf die Clients

| Aktion | Wirkt auf |
| --- | --- |
| Klick auf einen Knoten, Klick auf das Auf-/Zuklapp-Symbol | nur den Client, auf dem geklickt wurde |
| `TREEV_SelectNode`, `TREEV_Expand`, `TREEV_Collapse`, `TREEV_ExpandAll`, `TREEV_CollapseAll` | **alle** verbundenen Clients |
| `TREEV_SelectNodeEx`, `TREEV_ExpandEx`, `TREEV_CollapseEx`, `TREEV_ExpandAllEx`, `TREEV_CollapseAllEx` | nur den Client mit der übergebenen Client-ID (`''` = alle Clients) |
| Änderungen am Baum (`SetData`, `CreateNode`, `Save`, `Reset` …) | alle Clients; geöffnete Zweige und Markierung bleiben je Client erhalten |

**Wichtig:** `TREEV_SelectNode()` markiert den Knoten auf **allen** Clients. Hat der Knoten eine `TargetID`, die auf ein Objekt verweist, wird dieses Objekt auf **allen** Clients geöffnet. Dasselbe gilt für `TREEV_SelectNodeEx()` mit leerer Client-ID.

### Nur den klickenden Client ansprechen

Verweist `TargetID` auf ein Script, wird es bei einem Klick mit diesen Werten aufgerufen:

| Variable | Inhalt |
| --- | --- |
| `$_IPS['TREEV_INSTANCE']` | ID der TreeView-Instanz (als String) |
| `$_IPS['TREEV_CLIENT']` | Client-ID des Clients, auf dem geklickt wurde |
| `$_IPS['TREEV_PATH']` | `IDPath` des geklickten Knotens als JSON |

Mit der Client-ID können die `…Ex`-Methoden gezielt nur diesen Client verändern:

```php
<?php
$instance = (int) $_IPS['TREEV_INSTANCE'];
$client = $_IPS['TREEV_CLIENT'] ?? '';

// Nach dem Klick einen anderen Knoten nur auf dem klickenden Client öffnen
TREEV_ExpandEx($instance, $client, ['heating']);
TREEV_SelectNodeEx($instance, $client, ['heating', 'flow']);
```

Die Client-ID gilt pro Seitenaufruf der Kachel; nach einem Neuladen hat der Client eine neue ID. Wird ein Script nicht durch einen Klick in der TreeView ausgelöst (z. B. durch einen Timer, ein Ereignis oder eine andere Kachel), steht keine Client-ID zur Verfügung. Symcon teilt einem Script nicht mit, von welchem Client es ausgelöst wurde.

## PHP-API

Alle Funktionen erwarten als erstes Argument die TreeView-Instanz-ID. Beispiele:

```php
$instance = 12345;

$tree = TREEV_GetData($instance);
$node = TREEV_GetNode($instance, ['heating', 'primary']);
$exists = TREEV_NodeExists($instance, ['heating', 'primary']);
$children = TREEV_GetChildren($instance, ['heating']);

TREEV_CreateNode($instance, ['heating'], 'primary', 'Solekreis');
TREEV_SetNodeName($instance, ['heating', 'primary'], 'Primärkreis');
TREEV_SetNodeVariable($instance, ['heating', 'primary'], 12345);
TREEV_SetNodeTarget($instance, ['heating', 'primary'], 12345);
TREEV_SetNodeIcon($instance, ['heating', 'primary'], 'thermometer');
TREEV_SetNodeBackground($instance, ['heating', 'primary'], 0x336699);
TREEV_SetNodePosition($instance, ['heating', 'primary'], 200);
TREEV_SetNodeParent($instance, ['heating', 'primary'], []); // zu Root verschieben
TREEV_DeleteNode($instance, ['primary']);

TREEV_SelectNode($instance, ['heating']);
$selected = TREEV_GetSelectedNode($instance);
TREEV_Expand($instance, ['heating']);
TREEV_Collapse($instance, ['heating']);
TREEV_ExpandAll($instance);
TREEV_CollapseAll($instance);

// Nur für einen bestimmten Client (siehe «Wirkung auf die Clients»)
TREEV_SelectNodeEx($instance, $clientID, ['heating']);
TREEV_ExpandEx($instance, $clientID, ['heating']);
TREEV_CollapseEx($instance, $clientID, ['heating']);
TREEV_ExpandAllEx($instance, $clientID);
TREEV_CollapseAllEx($instance, $clientID);
```

Ein kompletter Baum wird als **Array von Root-Knoten** übergeben, auch wenn es nur einen Root-Knoten gibt:

```php
TREEV_SetData($instance, [
    [
        'ID' => 'heating',
        'Name' => 'Heizung',
        'Children' => [
            ['ID' => 'flow', 'Name' => 'Vorlauf', 'VariableID' => 12345]
        ]
    ]
]);

TREEV_MergeData($instance, [
    ['ID' => 'return', 'Name' => 'Rücklauf']
], ['heating']);

TREEV_Save($instance);  // Laufzeitbaum dauerhaft in die Instanzkonfiguration übernehmen
TREEV_Reset($instance); // ungespeicherte Änderungen und Auswahl verwerfen
```

`TREEV_MergeData()` führt einen oder mehrere Teilbäume unter dem angegebenen Parent-Pfad (`[]` = Root) zusammen:

- **Neue Knoten** (ID unter den Geschwistern noch nicht vorhanden) werden eingefügt; `Name` ist dafür Pflicht. Ohne `Position` werden sie hinter den bestehenden Geschwistern angehängt.
- **Bestehende Knoten** (gleiche ID unter denselben Geschwistern) werden aktualisiert: Nur die übergebenen Felder (`Name`, `Position`, `VariableID`, `Icon`, `Background`, `TargetID`) werden geändert, `Children` werden rekursiv nach denselben Regeln zusammengeführt. Nicht übergebene Felder und Kindknoten bleiben unverändert. `Icon = ''` bzw. `Background = -1` entfernen den Wert.

```php
// Namen von 'flow' ändern und unter 'flow' einen neuen Knoten anlegen
TREEV_MergeData($instance, [
    ['ID' => 'flow', 'Name' => 'Vorlauf primär', 'Children' => [
        ['ID' => 'temp', 'Name' => 'Temperatur', 'VariableID' => 12345]
    ]]
], ['heating']);
```

Weitere API-Methoden: `TREEV_GetChildren`, `TREEV_SetNodePosition`, `TREEV_SetNodeName`, `TREEV_SetNodeVariable`, `TREEV_SetNodeIcon`, `TREEV_SetNodeBackground`, `TREEV_SetNodeTarget`. Mutierende Methoden liefern im Regelfall `bool`; `TREEV_Reset`, `TREEV_ExpandAll`, `TREEV_CollapseAll`, `TREEV_ExpandAllEx` und `TREEV_CollapseAllEx` liefern keinen Rückgabewert. Pfade dürfen Zahlen enthalten; `[1, 2]` und `['1', '2']` adressieren denselben Knoten.

Wird eine in der Konfiguration verwendete Variable oder ein Zielobjekt später gelöscht, bleibt die Instanz gültig: Der Bezug wird als «Kein(e)» behandelt und im Konfigurationsformular als Warnung angezeigt.

`Position` wird pro Geschwistergruppe ausgewertet. Die Reihenfolge ist `Position` aufsteigend, danach `Name` und `ID`. Fehlende Positionen werden automatisch vergeben.

## Laufzeitdaten und Speicherung

API-Änderungen wirken zunächst nur zur Laufzeit. `TREEV_Save()` schreibt den aktuellen Baum in die Instanzkonfiguration. Auch das reguläre Übernehmen der Instanzkonfiguration setzt den Laufzeitbaum auf die konfigurierte Struktur zurück. Ein vollständig leerer Baum ist zulässig.

Zum Verwerfen ungespeicherter Änderungen gibt es zwei Methoden:

| Methode | Laufzeitänderungen | Auswahl (`Selection`) |
| --- | --- | --- |
| `TREEV_ClearRuntimeData()` | verworfen | bleibt erhalten, solange der Knoten im konfigurierten Baum existiert |
| `TREEV_Reset()` | verworfen | wird immer gelöscht |

## Einschränkungen und Hinweise

- Die globale Auswahlvariable ist kein individueller Zustand je Visualisierungsclient.
- `sessionStorage` ist browser-/tabbezogen; seine Verfügbarkeit und Lebensdauer hängen von der Visualisierungsumgebung ab.
- Die Navigation verwendet die Symcon-Funktion `openObject()`. Die konkrete Detailansicht bestimmt Symcon.
- Navigation durch `TREEV_SelectNode()` wird an alle Clients verteilt; ein Klick in der Kachel navigiert nur den betreffenden Client. Für einen einzelnen Client `TREEV_SelectNodeEx()` mit der Client-ID verwenden.
- Das Modul enthält keine eigene Archivgraph-Darstellung und keine eigene Rechteverwaltung.

## Unterstützung

Dieses Modul ist kostenlos. Schenkungen als Unterstützung für den Autor werden
gerne angenommen: PayPal an ueli.schweizer@cassanis.fr

## Tests

Getestet wurden bisher native Baumkonfiguration, Drag & Drop inklusive Parent-Wechsel, Navigation zu Variablen mit/ohne Archiv und Action Script, lokale Zustandswiederherstellung in zwei Browser-Fenstern sowie ein API-Test mit 41 erfolgreichen Prüfungen. Der zusätzliche Strict-Test wurde mit 67 PASS, 0 FAIL abgeschlossen (insgesamt 108 erfolgreiche Prüfungen). Fehlerberichte bitte mit Symcon-Version, Reproduktionsschritten und gegebenenfalls Debug-Ausgabe einreichen.

## Versionshinweis

**Version 1.1, Build 18 (Stable):**
- Neue Funktionen `TREEV_SelectNodeEx`, `TREEV_ExpandEx`, `TREEV_CollapseEx`, `TREEV_ExpandAllEx` und `TREEV_CollapseAllEx` wirken nur auf einen Client; Target-Scripts erhalten dafür `TREEV_INSTANCE`, `TREEV_CLIENT` und `TREEV_PATH`.
- Die Auswahl bleibt bei `TREEV_Save()`, `TREEV_ClearRuntimeData()` und beim Übernehmen der Konfiguration erhalten und wird nur gelöscht, wenn der Knoten oder einer seiner Vorfahren gelöscht wird.
- `TREEV_MergeData()` aktualisiert bestehende Knoten, statt nur neue einzufügen.
- Gelöschte Variablen oder Ziele machen die Instanz nicht mehr ungültig, sondern erscheinen als Warnung im Konfigurationsformular.
- Der Abstand oben richtet sich danach, ob ein Kacheltitel angezeigt wird.
- Darstellung ohne CSS `:has()`, damit auch ältere Browser die Verbindungslinien korrekt zeigen.
- Live-Werte springen nach dem Auswählen eines anderen Knotens nicht mehr auf den alten Wert zurück.
- Meldungen übersetzt, veraltete Übersetzungen entfernt, Code lesbarer strukturiert.

**Build 16 (Beta):** Fullscreen-Unterstützung ab Symcon 9.1; Script-Ausführung über `TargetID` bei Benutzerklick; einheitlicher Objekt-ID-Default `1` mit Migration bisheriger `-1`-/`0`-Werte; korrigierte Einrückung von Blattknoten sowie Verbindungslinien bei der Plus/Minus-Darstellung.

**Build 15 (Beta):** Symcon-Kit-Icons (`fa-kit`) werden zusätzlich zu Font-Awesome-Icons unterstützt; `Transparent` und leere Icons belegen keinen Platz mehr. Einrückung der Baumstruktur verbessert; Kompatibilität in den Metadaten auf Symcon 8.1 gesetzt.

**Build 14 (Beta):** Ungültige `VariableID`-Werte ausserhalb des Symcon-ID-Bereichs werden vor der Existenzprüfung zurückgewiesen. Metadaten und Beta-Dokumentation aktualisiert.

Drag & Drop im nativen Tree-Editor benötigt eine Symcon/Ninja-Version, in der die `changeOrder`-Korrektur enthalten ist. Falls das Verschieben nicht funktioniert, bitte zunächst Ninja aktualisieren.
