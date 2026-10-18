# TreeView für IP-Symcon

**Version:** 1.0 · **Status:** Beta (Build 14) · **Modulpräfix:** `TREEV` · **Hersteller:** Cassanis Automation

TreeView ist eine universelle HTML-Kachel zur Darstellung und Bedienung beliebig tief verschachtelter Hierarchien. Die Baumstruktur ist unabhängig vom IP-Symcon-Objektbaum und kann entweder im Konfigurationsformular oder über PHP aufgebaut werden.

## Funktionen

- Nativer Symcon-Baumeditor: Knoten per Drag & Drop umsortieren oder mitsamt Unterbaum zu einem anderen Parent verschieben.
- Drei Darstellungen für Ein-/Ausklappen: Box mit Verbindungslinien, Plus/Minus und Chevron.
- Optionaler Variablenwert, Icon, Hintergrundfarbe und Navigationsziel je Knoten.
- Klick auf einen Knoten aktualisiert die globale Auswahlvariable. Ist ein `TargetID` konfiguriert, öffnet die Kachel das Objekt zusätzlich mit `openObject()` auf dem aktuellen Client.
- Aufgeklappte Zweige und visuelle Auswahl werden pro Browser-Tab und Instanz in `sessionStorage` gehalten; ein Wechsel in die Detailansicht und zurück erhält den Zustand, soweit die Visualisierungsumgebung den Speicher beibehält.
- Konfigurierbares automatisches Zuklappen: Aus, Nur bei Auswahl, Immer.
- Laufzeit-API zum Lesen, Ändern, Verschieben, Einfügen und Löschen von Knoten; Änderungen lassen sich speichern oder verwerfen.

## Konfiguration

Im Feld **Knoten** den Baum aufbauen. Die `ID` muss innerhalb desselben Parents eindeutig sein; unter unterschiedlichen Parents darf dieselbe `ID` erneut vorkommen. Ein Knoten wird deshalb über den vollständigen `IDPath` angesprochen, z. B. `['heating', 'primary', 'vorlauf']`.

| Feld | Bedeutung |
| --- | --- |
| `ID` | Technische ID des Knotens, eindeutig unter Geschwistern |
| `Name` | Angezeigter Text |
| `VariableID` | Optional: aktuelle, formatierte Variable anzeigen; `0` bedeutet keine Wertanzeige |
| `Icon` | Optionales Icon; ein explizites Icon hat Vorrang vor dem Variablen-Icon |
| `Background` | Optionaler RGB-Farbwert; `-1` bedeutet keine eigene Hintergrundfarbe |
| `TargetID` | Optionales Navigationsziel; `0`/`1` bedeutet keine Navigation |

`VariableID` und `TargetID` sind unabhängig. Für einen aktuellen Wert **und** eine Detailansicht kann dieselbe Variable in beiden Feldern stehen. Bei einer archivierten Variablen kann die Symcon-Detailansicht auch den Archivgraphen anbieten.

## Auswahl und mehrere Clients

Die automatisch angelegte Stringvariable **Selection** enthält nach einem Klick ein JSON-Objekt mit `IDPath` und `Node`. Ohne Auswahl enthält sie `[]`. Sie beschreibt **die letzte globale Auswahl aller Clients** und ist deshalb keine clientbezogene Auswahlvariable. Die grüne Markierung und die geöffneten Zweige sind dagegen clientbezogen. Die PHP-Methode `TREEV_SelectNode()` ist bewusst eine globale Aktion und kann die verbundenen Visualisierungen beeinflussen.

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
TREEV_Reset($instance); // ungespeicherte Änderungen verwerfen
```

Weitere API-Methoden: `TREEV_GetChildren`, `TREEV_SetNodePosition`, `TREEV_SetNodeName`, `TREEV_SetNodeVariable`, `TREEV_SetNodeIcon`, `TREEV_SetNodeBackground`, `TREEV_SetNodeTarget`. Mutierende Methoden liefern im Regelfall `bool`; `TREEV_Reset`, `TREEV_ExpandAll` und `TREEV_CollapseAll` liefern keinen Rückgabewert.

`Position` wird pro Geschwistergruppe ausgewertet. Die Reihenfolge ist `Position` aufsteigend, danach `Name` und `ID`. Fehlende Positionen werden automatisch vergeben.

## Laufzeitdaten und Speicherung

API-Änderungen wirken zunächst nur zur Laufzeit. `TREEV_Save()` schreibt den aktuellen Baum in die Instanzkonfiguration; `TREEV_Reset()` verwirft ungespeicherte Änderungen. Auch das reguläre Übernehmen der Instanzkonfiguration setzt den Laufzeitbaum auf die konfigurierte Struktur zurück. Ein vollständig leerer Baum ist zulässig.

## Einschränkungen und Hinweise

- Die globale Auswahlvariable ist kein individueller Zustand je Visualisierungsclient.
- `sessionStorage` ist browser-/tabbezogen; seine Verfügbarkeit und Lebensdauer hängen von der Visualisierungsumgebung ab.
- Die Navigation verwendet die Symcon-Funktion `openObject()`. Die konkrete Detailansicht bestimmt Symcon.
- Navigation durch `TREEV_SelectNode()` wird als Visualisierungsnachricht verteilt; ein Klick in der Kachel navigiert nur den betreffenden Client.
- Das Modul enthält keine eigene Archivgraph-Darstellung und keine eigene Rechteverwaltung.

## Beta-Test

Getestet wurden bisher native Baumkonfiguration, Drag & Drop inklusive Parent-Wechsel, Navigation zu Variablen mit/ohne Archiv und Action Script, lokale Zustandswiederherstellung in zwei Browser-Fenstern sowie ein API-Test mit 41 erfolgreichen Prüfungen. Der zusätzliche Strict-Test wurde mit 67 PASS, 0 FAIL abgeschlossen (insgesamt 108 erfolgreiche Prüfungen). Fehlerberichte bitte mit Symcon-Version, Reproduktionsschritten und gegebenenfalls Debug-Ausgabe einreichen.

## Versionshinweis

**Build 14 (Beta):** Ungültige `VariableID`-Werte ausserhalb des Symcon-ID-Bereichs werden vor der Existenzprüfung zurückgewiesen. Metadaten und Beta-Dokumentation aktualisiert.

Drag & Drop im nativen Tree-Editor benötigt eine Symcon/Ninja-Version, in der die `changeOrder`-Korrektur enthalten ist. Falls das Verschieben nicht funktioniert, bitte zunächst Ninja aktualisieren.
