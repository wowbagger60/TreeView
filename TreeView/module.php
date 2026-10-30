<?php

declare(strict_types=1);

class TreeView extends IPSModuleStrict
{
    private const NODE_FIELDS = ['ID', 'Name', 'VariableID', 'Icon', 'Background', 'TargetID'];

    // Cache of the active tree for the current call, keyed by the raw data it was built from.
    // The key makes the cache safe even if Symcon reuses the module object.
    private string $activeTreeSource = '';
    private array $activeTree = [];

    public function Create(): void
    {
        parent::Create();

        // The native Tree is the only persisted node configuration.
        $this->RegisterPropertyString('TreeNodes', '[]');
        $this->RegisterPropertyString('ExpandIndicator', 'Box');
        $this->RegisterPropertyInteger('Indentation', 20);
        $this->RegisterPropertyInteger('InitialFontScale', 110);
        $this->RegisterPropertyInteger('FontScale', 100);
        $this->RegisterPropertyInteger('MinimumFontScale', 75);
        $this->RegisterPropertyString('AutoCollapseMode', 'Off');
        $this->RegisterPropertyBoolean('ShowIcons', true);
        $this->RegisterPropertyBoolean('ShowValues', true);

        $this->RegisterVariableString('Selection', 'Selection');
        $this->SetBuffer('RuntimeData', '');
        $this->SetBuffer('ConfigurationError', '');
        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN : 1);
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Object checks and message registrations require a fully started kernel.
        // IPS_KERNELSTARTED calls ApplyChanges again.
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        if ($this->MigrateObjectIDs()) {
            return;
        }

        $warnings = [];
        try {
            $rows = $this->ReadConfiguredRows();
            $error = $this->ValidateRows($rows, $warnings);
        } catch (Throwable $e) {
            $rows = [];
            $error = $this->Translate($e->getMessage());
        }
        $this->SendDebug('ApplyChanges', sprintf('Validating %d configured node(s)', count($rows)), 0);

        if ($error !== null) {
            $this->SetBuffer('ConfigurationError', $error);
            $this->SetStatus(201);
            $this->ReloadForm();
            $this->SendDebug('Configuration', $error, 0);
            return;
        }

        // Missing variables or targets (e.g. deleted later) do not invalidate the tree.
        // They are treated as "none" and reported as a warning.
        $this->SetBuffer('ConfigurationError', implode("\n", $warnings));
        foreach ($warnings as $warning) {
            $this->SendDebug('Configuration', $warning, 0);
        }
        $this->SetStatus(102);
        $this->ReloadForm();

        // ApplyChanges always makes the persisted configuration authoritative.
        // Runtime changes only survive when they have explicitly been saved.
        $this->SetBuffer('RuntimeData', '');
        $tree = $this->GetActiveTree();
        // The selection is kept as long as the selected node still exists.
        $this->ReconcileSelection($tree);
        $this->RebuildReferences();
        $this->SendDebug('ApplyChanges', sprintf('Configuration valid: %d root node(s), %d reference(s)', count($tree), count($this->GetReferenceList())), 0);
        $this->PushFullUpdate();
    }

    public function GetConfigurationForm(): string
    {
        $content = file_get_contents(__DIR__ . '/form.json');
        if ($content === false) {
            return '{}';
        }
        $form = json_decode($content, true);
        if (!is_array($form)) {
            return '{}';
        }

        $message = $this->GetBuffer('ConfigurationError');
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'ConfigurationError') {
                $element['caption'] = $message;
                $element['visible'] = $message !== '';
            }
        }
        unset($element);

        return json_encode($form, JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/assets/index.html');
        if ($html === false) {
            return '';
        }
        $initialMessage = json_encode($this->BuildFullMessage());
        return $html . '<script>window.__treevInstanceID=' . $this->InstanceID . ';handleMessage(' . $initialMessage . ');</script>';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'UserSelect':
                $this->HandleUserSelect((string) $Value);
                break;

            case 'Select':
                $path = json_decode((string) $Value, true);
                if (is_array($path)) {
                    $this->SelectNodeInternal($path, false, false);
                }
                break;

            default:
                throw new Exception($this->Translate('Invalid Ident: ') . $Ident);
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                break;

            case VM_UPDATE:
                $this->UpdateVisualizationValue(json_encode([
                    'type'       => 'value',
                    'variableID' => $SenderID,
                    'value'      => $this->FormatVariable($SenderID)
                ], JSON_UNESCAPED_UNICODE));
                break;
        }
    }

    // ---------------------------------------------------------------------
    // Public API: tree data
    // ---------------------------------------------------------------------

    public function SetData(array $Data): bool
    {
        $error = $this->ValidateNestedTree($Data);
        if ($error !== null) {
            $this->SendDebug('SetData', $error, 0);
            return false;
        }
        return $this->CommitRuntimeTree($Data, 'SetData');
    }

    public function MergeData(array $Data, array $ParentIDPath = []): bool
    {
        // Name is optional for nodes that already exist; MergeNodes requires it for new nodes.
        $error = $this->ValidateNestedTree($Data, [], false);
        if ($error !== null) {
            $this->SendDebug('MergeData', $error, 0);
            return false;
        }

        $ParentIDPath = $this->NormalizePath($ParentIDPath);
        $tree = $this->GetActiveTree();
        if ($ParentIDPath === []) {
            $merged = $this->MergeNodes($tree, $Data);
        } else {
            $merged = $this->UpdateNodeAtPath($tree, $ParentIDPath, function (array &$parent) use ($Data): bool {
                $parent['Children'] ??= [];
                return $this->MergeNodes($parent['Children'], $Data);
            });
        }
        if (!$merged) {
            $this->SendDebug('MergeData', 'Parent not found or new node without name', 0);
            return false;
        }
        return $this->CommitRuntimeTree($tree, 'MergeData');
    }

    public function GetData(): array
    {
        return $this->GetActiveTree();
    }

    public function ClearRuntimeData(): void
    {
        // Discards runtime changes but keeps the selection if the node still exists.
        $this->SetBuffer('RuntimeData', '');
        $this->ReconcileSelection($this->GetActiveTree());
        $this->RebuildReferences();
        $this->SendDebug('ClearRuntimeData', 'Runtime tree cleared; configured tree active', 0);
        $this->PushFullUpdate();
    }

    public function Reset(): void
    {
        // Discards runtime changes and the selection.
        $this->SetBuffer('RuntimeData', '');
        $this->SetValue('Selection', '[]');
        $this->RebuildReferences();
        $this->SendDebug('Reset', 'Runtime changes discarded; configured tree restored', 0);
        $this->PushFullUpdate();
    }

    public function Save(): bool
    {
        $rows = [];
        $this->TreeToConfigRows($this->GetActiveTree(), 1, $rows);
        IPS_SetProperty($this->InstanceID, 'TreeNodes', json_encode($this->FlatRowsToNativeTreeRows($rows), JSON_UNESCAPED_UNICODE));
        $this->SetBuffer('RuntimeData', '');
        // ApplyChanges keeps the selection as long as the selected node still exists.
        IPS_ApplyChanges($this->InstanceID);
        $this->SendDebug('Save', sprintf('Saved %d configured node(s)', count($rows)), 0);
        return true;
    }

    // ---------------------------------------------------------------------
    // Public API: single nodes
    // ---------------------------------------------------------------------

    public function GetNode(array $IDPath): array|false
    {
        return $this->FindNode($this->GetActiveTree(), $this->NormalizePath($IDPath)) ?? false;
    }

    public function NodeExists(array $IDPath): bool
    {
        return $this->FindNode($this->GetActiveTree(), $this->NormalizePath($IDPath)) !== null;
    }

    public function GetChildren(array $IDPath = []): array|false
    {
        if ($IDPath === []) {
            return $this->GetActiveTree();
        }
        $node = $this->FindNode($this->GetActiveTree(), $this->NormalizePath($IDPath));
        return $node === null ? false : ($node['Children'] ?? []);
    }

    public function CreateNode(array $ParentIDPath, string $ID, string $Name): bool
    {
        $ParentIDPath = $this->NormalizePath($ParentIDPath);
        $ID = trim($ID);
        $Name = trim($Name);
        if ($ID === '' || $Name === '') {
            return false;
        }

        $tree = $this->GetActiveTree();
        $siblings = $this->GetChildrenFromTree($tree, $ParentIDPath);
        if ($siblings === null || $this->FindSiblingIndex($siblings, $ID) !== null) {
            return false;
        }

        $node = [
            'ID'       => $ID,
            'Name'     => $Name,
            'Position' => $this->MaxPosition($siblings) + 100,
            'Children' => []
        ];
        if (!$this->InsertNodeAtPath($tree, $ParentIDPath, $node)) {
            return false;
        }
        return $this->CommitRuntimeTree($tree, 'CreateNode');
    }

    public function DeleteNode(array $IDPath): bool
    {
        $IDPath = $this->NormalizePath($IDPath);
        $tree = $this->GetActiveTree();
        $removed = null;
        if (!$this->RemoveNodeAtPath($tree, $IDPath, $removed)) {
            return false;
        }
        return $this->CommitRuntimeTree($tree, 'DeleteNode');
    }

    public function SetNodeParent(array $IDPath, array $ParentIDPath): bool
    {
        $IDPath = $this->NormalizePath($IDPath);
        $ParentIDPath = $this->NormalizePath($ParentIDPath);
        if ($IDPath === [] || $IDPath === $ParentIDPath) {
            return false;
        }
        // A node may not be attached below itself. IDPath makes this a simple prefix check.
        if ($this->IsPathPrefix($IDPath, $ParentIDPath)) {
            return false;
        }

        $tree = $this->GetActiveTree();
        if ($ParentIDPath !== [] && $this->FindNode($tree, $ParentIDPath) === null) {
            return false;
        }
        $removed = null;
        if (!$this->RemoveNodeAtPath($tree, $IDPath, $removed)) {
            return false;
        }
        $siblings = $this->GetChildrenFromTree($tree, $ParentIDPath);
        if ($siblings === null || $this->FindSiblingIndex($siblings, (string) $removed['ID']) !== null) {
            return false;
        }

        $removed['Position'] = $this->MaxPosition($siblings) + 100;
        if (!$this->InsertNodeAtPath($tree, $ParentIDPath, $removed)) {
            return false;
        }
        $move = ['from' => $IDPath, 'to' => array_merge($ParentIDPath, [(string) $removed['ID']])];
        return $this->CommitRuntimeTree($tree, 'SetNodeParent', $move);
    }

    public function SetNodePosition(array $IDPath, int $Position): bool
    {
        return $this->UpdateNode($IDPath, 'SetNodePosition', function (array &$node) use ($Position): bool {
            $node['Position'] = $Position;
            return true;
        });
    }

    public function SetNodeName(array $IDPath, string $Name): bool
    {
        $Name = trim($Name);
        if ($Name === '') {
            return false;
        }
        return $this->UpdateNode($IDPath, 'SetNodeName', function (array &$node) use ($Name): bool {
            $node['Name'] = $Name;
            return true;
        });
    }

    public function SetNodeVariable(array $IDPath, int $VariableID): bool
    {
        $VariableID = max(1, $VariableID);
        if ($VariableID > 1 && !$this->VariableExists($VariableID)) {
            return false;
        }
        return $this->UpdateNode($IDPath, 'SetNodeVariable', function (array &$node) use ($VariableID): bool {
            $node['VariableID'] = $VariableID;
            return true;
        });
    }

    public function SetNodeIcon(array $IDPath, string $Icon): bool
    {
        // An empty icon removes the explicit icon; NormalizeTree drops it.
        $Icon = trim($Icon);
        return $this->UpdateNode($IDPath, 'SetNodeIcon', function (array &$node) use ($Icon): bool {
            $node['Icon'] = $Icon;
            return true;
        });
    }

    public function SetNodeBackground(array $IDPath, int $Background): bool
    {
        // -1 removes the background; NormalizeTree drops it.
        if ($Background < -1 || $Background > 0xFFFFFF) {
            return false;
        }
        return $this->UpdateNode($IDPath, 'SetNodeBackground', function (array &$node) use ($Background): bool {
            $node['Background'] = $Background;
            return true;
        });
    }

    public function SetNodeTarget(array $IDPath, int $TargetID): bool
    {
        $TargetID = max(1, $TargetID);
        if ($TargetID > 1 && !IPS_ObjectExists($TargetID)) {
            return false;
        }
        return $this->UpdateNode($IDPath, 'SetNodeTarget', function (array &$node) use ($TargetID): bool {
            $node['TargetID'] = $TargetID;
            return true;
        });
    }

    // ---------------------------------------------------------------------
    // Public API: selection and expansion
    //
    // SelectNode, Expand, Collapse, ExpandAll and CollapseAll affect all clients.
    // The ...Ex variants only affect the client whose ID is passed ('' = all clients).
    // ---------------------------------------------------------------------

    public function SelectNode(array $IDPath): bool
    {
        // All clients receive the selection and, if the node has a target, the navigation request.
        // Tile clicks use SelectNodeInternal(..., false, false) and navigate locally instead.
        return $this->SelectNodeInternal($IDPath, true, true);
    }

    public function SelectNodeEx(string $ClientID, array $IDPath): bool
    {
        return $this->SelectNodeInternal($IDPath, true, true, $ClientID);
    }

    public function GetSelectedNode(): array|false
    {
        $selection = json_decode((string) $this->GetValue('Selection'), true);
        return is_array($selection) && isset($selection['IDPath'], $selection['Node']) ? $selection : false;
    }

    public function Expand(array $IDPath): bool
    {
        return $this->PushExpandCommand('expand', $IDPath, '');
    }

    public function Collapse(array $IDPath): bool
    {
        return $this->PushExpandCommand('collapse', $IDPath, '');
    }

    public function ExpandAll(): void
    {
        $this->SendToClients(['type' => 'expandAll'], '');
    }

    public function CollapseAll(): void
    {
        $this->SendToClients(['type' => 'collapseAll'], '');
    }

    public function ExpandEx(string $ClientID, array $IDPath): bool
    {
        return $this->PushExpandCommand('expand', $IDPath, $ClientID);
    }

    public function CollapseEx(string $ClientID, array $IDPath): bool
    {
        return $this->PushExpandCommand('collapse', $IDPath, $ClientID);
    }

    public function ExpandAllEx(string $ClientID): void
    {
        $this->SendToClients(['type' => 'expandAll'], $ClientID);
    }

    public function CollapseAllEx(string $ClientID): void
    {
        $this->SendToClients(['type' => 'collapseAll'], $ClientID);
    }

    // ---------------------------------------------------------------------
    // Selection, client messages
    // ---------------------------------------------------------------------

    private function HandleUserSelect(string $value): void
    {
        // Current tiles send {client, path}; tiles loaded before the update send the bare path.
        $data = json_decode($value, true);
        if (!is_array($data)) {
            return;
        }
        $client = '';
        $path = $data;
        if (!array_is_list($data)) {
            $client = trim((string) ($data['client'] ?? ''));
            $path = is_array($data['path'] ?? null) ? $data['path'] : [];
        }
        $path = $this->NormalizePath($path);
        if (!$this->SelectNodeInternal($path, false, false)) {
            return;
        }

        $node = $this->FindNode($this->GetActiveTree(), $path);
        $target = (int) ($node['TargetID'] ?? 1);
        if ($target > 1 && IPS_ScriptExists($target)) {
            // TREEV_CLIENT lets the script address only the clicking client via the ...Ex functions.
            IPS_RunScriptEx($target, [
                'TREEV_INSTANCE' => (string) $this->InstanceID,
                'TREEV_CLIENT'   => $client,
                'TREEV_PATH'     => json_encode($path, JSON_UNESCAPED_UNICODE)
            ]);
        }
    }

    private function SelectNodeInternal(array $IDPath, bool $navigate, bool $broadcast, string $client = ''): bool
    {
        $IDPath = $this->NormalizePath($IDPath);
        if ($IDPath === []) {
            $this->SetValue('Selection', '[]');
            if ($broadcast) {
                $this->SendToClients(['type' => 'select', 'path' => []], $client);
            }
            return true;
        }

        $node = $this->FindNode($this->GetActiveTree(), $IDPath);
        if ($node === null) {
            return false;
        }
        $json = $this->EncodeSelection($IDPath, $node);
        $this->SetValue('Selection', $json);

        $message = ['type' => 'select', 'path' => $IDPath];
        $targetID = (int) ($node['TargetID'] ?? 1);
        if ($navigate && $targetID > 1) {
            if (IPS_ObjectExists($targetID)) {
                $message['navigateTarget'] = $targetID;
            } else {
                $this->SendDebug('Navigation', 'Target object not found: ' . $targetID, 0);
            }
        }
        if ($broadcast) {
            $this->SendToClients($message, $client);
        }
        $this->SendDebug('Selection', $json, 0);
        return true;
    }

    private function ReconcileSelection(array $tree, ?array $move = null): void
    {
        // Keeps the selection in sync with the tree: follows a moved node, refreshes the
        // node data and clears the selection if the node (or one of its parents) was deleted.
        $selection = $this->GetSelectedNode();
        if ($selection === false) {
            if ((string) $this->GetValue('Selection') !== '[]') {
                $this->SetValue('Selection', '[]');
            }
            return;
        }

        $path = $this->NormalizePath($selection['IDPath']);
        if ($move !== null && $this->IsPathPrefix($move['from'], $path)) {
            $path = array_merge($move['to'], array_slice($path, count($move['from'])));
        }
        $node = $this->FindNode($tree, $path);
        $this->SetValue('Selection', $node === null ? '[]' : $this->EncodeSelection($path, $node));
    }

    private function EncodeSelection(array $path, array $node): string
    {
        unset($node['Children']);
        return json_encode(['IDPath' => $path, 'Node' => $node], JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    private function PushExpandCommand(string $type, array $path, string $client): bool
    {
        $path = $this->NormalizePath($path);
        if ($this->FindNode($this->GetActiveTree(), $path) === null) {
            return false;
        }
        $this->SendToClients(['type' => $type, 'path' => $path], $client);
        return true;
    }

    private function SendToClients(array $message, string $client): void
    {
        // Symcon delivers every visualization message to all clients. A client ID makes
        // all other clients ignore the message (see handleMessage in index.html).
        $client = trim($client);
        if ($client !== '') {
            $message['client'] = $client;
        }
        $this->UpdateVisualizationValue(json_encode($message, JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    private function PushFullUpdate(): void
    {
        $this->UpdateVisualizationValue($this->BuildFullMessage());
    }

    private function PushTreeUpdate(?array $move = null): void
    {
        $message = $this->BuildMessage('tree');
        if ($move !== null) {
            $message['move'] = $move;
        }
        $this->UpdateVisualizationValue(json_encode($message, JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    private function BuildFullMessage(): string
    {
        return json_encode($this->BuildMessage('full'), JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function BuildMessage(string $type): array
    {
        $selection = $this->GetSelectedNode();
        return [
            'type'   => $type,
            'tree'   => $this->DecorateTree($this->GetActiveTree()),
            'design' => [
                'indicator'        => $this->ReadPropertyString('ExpandIndicator'),
                'indent'           => max(4, $this->ReadPropertyInteger('Indentation')),
                'initialFontScale' => max(50, min(200, $this->ReadPropertyInteger('InitialFontScale'))),
                'fontScale'        => max(50, min(100, $this->ReadPropertyInteger('FontScale'))),
                'minFontScale'     => max(40, min(200, $this->ReadPropertyInteger('MinimumFontScale'))),
                'autoCollapseMode' => $this->ReadPropertyString('AutoCollapseMode'),
                'showIcons'        => $this->ReadPropertyBoolean('ShowIcons'),
                'showValues'       => $this->ReadPropertyBoolean('ShowValues')
            ],
            'selectedPath' => $selection === false ? [] : $selection['IDPath'],
            'emptyText'    => $this->Translate('No nodes configured')
        ];
    }

    private function DecorateTree(array $tree): array
    {
        // Adds display data for the tile. Deleted variables and targets are sent as "none" (1).
        foreach ($tree as &$node) {
            $variableID = (int) ($node['VariableID'] ?? 1);
            if (!$this->VariableExists($variableID)) {
                $variableID = 1;
            }
            $targetID = max(1, (int) ($node['TargetID'] ?? 1));
            if ($targetID > 1 && !IPS_ObjectExists($targetID)) {
                $targetID = 1;
            }
            $explicitIcon = trim((string) ($node['Icon'] ?? ''));

            $node['VariableID'] = $variableID;
            $node['Value'] = $this->FormatVariable($variableID);
            $node['EffectiveIcon'] = $explicitIcon !== '' ? $explicitIcon : $this->GetVariableIcon($variableID);
            $node['TargetID'] = $targetID;
            $node['TargetIsScript'] = $targetID > 1 && IPS_ScriptExists($targetID);
            $node['Children'] = $this->DecorateTree($node['Children'] ?? []);
        }
        unset($node);
        return $tree;
    }

    // ---------------------------------------------------------------------
    // Active tree and runtime changes
    // ---------------------------------------------------------------------

    private function GetActiveTree(): array
    {
        $runtime = $this->GetBuffer('RuntimeData');
        $source = $runtime !== '' ? 'runtime:' . $runtime : 'config:' . $this->ReadPropertyString('TreeNodes');
        if ($source === $this->activeTreeSource) {
            return $this->activeTree;
        }

        $tree = $runtime !== '' ? json_decode($runtime, true) : null;
        if (!is_array($tree)) {
            try {
                $tree = $this->ConfigRowsToTree($this->ReadConfiguredRows());
            } catch (Throwable) {
                // ApplyChanges reports an invalid configuration; the tile shows an empty tree.
                $tree = [];
            }
        }
        $this->activeTreeSource = $source;
        $this->activeTree = $tree;
        return $tree;
    }

    private function CommitRuntimeTree(array $tree, string $source, ?array $move = null): bool
    {
        $tree = $this->NormalizeTree($tree);
        $this->SetBuffer('RuntimeData', json_encode($tree, JSON_UNESCAPED_UNICODE));
        $this->RebuildReferences();
        $this->ReconcileSelection($tree, $move);
        $this->PushTreeUpdate($move);
        $this->SendDebug($source, sprintf('Tree updated: %d root node(s)', count($tree)), 0);
        return true;
    }

    private function UpdateNode(array $path, string $source, callable $callback): bool
    {
        $path = $this->NormalizePath($path);
        $tree = $this->GetActiveTree();
        if (!$this->UpdateNodeAtPath($tree, $path, $callback)) {
            return false;
        }
        return $this->CommitRuntimeTree($tree, $source);
    }

    private function MergeNodes(array &$siblings, array $incoming): bool
    {
        // Existing nodes (same ID among the siblings) only get the supplied fields changed and
        // their children merged recursively. New nodes are appended behind the existing siblings
        // unless they have a Position. NormalizeTree cleans up the values afterwards.
        $maxPosition = $this->MaxPosition($siblings);
        foreach ($incoming as $node) {
            $index = $this->FindSiblingIndex($siblings, trim((string) $node['ID']));

            if ($index === null) {
                if (trim((string) ($node['Name'] ?? '')) === '') {
                    return false;
                }
                if (!array_key_exists('Position', $node)) {
                    $maxPosition += 100;
                    $node['Position'] = $maxPosition;
                }
                $siblings[] = $node;
                continue;
            }

            foreach (['Name', 'Position', 'VariableID', 'Icon', 'Background', 'TargetID'] as $field) {
                if (array_key_exists($field, $node)) {
                    $siblings[$index][$field] = $node[$field];
                }
            }
            if (isset($node['Children'])) {
                $siblings[$index]['Children'] ??= [];
                if (!$this->MergeNodes($siblings[$index]['Children'], $node['Children'])) {
                    return false;
                }
            }
        }
        return true;
    }

    private function NormalizeTree(array $tree): array
    {
        $result = [];
        $autoPosition = 0;
        foreach ($tree as $node) {
            $autoPosition += 100;
            $normalized = [
                'ID'         => trim((string) $node['ID']),
                'Name'       => trim((string) $node['Name']),
                'Position'   => (int) ($node['Position'] ?? $autoPosition),
                'VariableID' => max(1, (int) ($node['VariableID'] ?? 1)),
                'TargetID'   => max(1, (int) ($node['TargetID'] ?? 1))
            ];
            $icon = trim((string) ($node['Icon'] ?? ''));
            if ($icon !== '') {
                $normalized['Icon'] = $icon;
            }
            $background = $this->NormalizeBackground($node['Background'] ?? -1);
            if ($background >= 0) {
                $normalized['Background'] = $background;
            }
            $normalized['Children'] = $this->NormalizeTree($node['Children'] ?? []);
            $result[] = $normalized;
        }

        usort($result, static function (array $a, array $b): int {
            return ($a['Position'] <=> $b['Position'])
                ?: strcasecmp($a['Name'], $b['Name'])
                ?: strcmp($a['ID'], $b['ID']);
        });
        return $result;
    }

    private function ValidateNestedTree(array $tree, array $path = [], bool $requireName = true): ?string
    {
        $seen = [];
        foreach ($tree as $node) {
            if (!is_array($node)) {
                return 'Invalid node at ' . json_encode($path) . '.';
            }
            $id = trim((string) ($node['ID'] ?? ''));
            if ($id === '') {
                return 'A node has no ID.';
            }
            if (($requireName || array_key_exists('Name', $node)) && trim((string) ($node['Name'] ?? '')) === '') {
                return 'Node "' . $id . '" has no name.';
            }
            if (isset($seen[$id])) {
                return 'Duplicate sibling ID "' . $id . '".';
            }
            $seen[$id] = true;

            $targetID = max(1, (int) ($node['TargetID'] ?? 1));
            if ($targetID > 1 && !IPS_ObjectExists($targetID)) {
                return 'TargetID ' . $targetID . ' does not exist.';
            }
            $variableID = (int) ($node['VariableID'] ?? 1);
            if ($variableID > 1 && !$this->VariableExists($variableID)) {
                return 'VariableID ' . $variableID . ' does not exist.';
            }

            $children = $node['Children'] ?? [];
            if (!is_array($children)) {
                return 'Children of "' . $id . '" must be an array.';
            }
            $error = $this->ValidateNestedTree($children, array_merge($path, [$id]), $requireName);
            if ($error !== null) {
                return $error;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // Configuration (native Tree) <-> nested tree
    // ---------------------------------------------------------------------

    private function MigrateObjectIDs(): bool
    {
        // Older versions stored -1 or 0 for "none"; Symcon object selectors use 1.
        // The native Tree stores flat rows linked by id/parent, so no recursion is needed.
        $rows = json_decode($this->ReadPropertyString('TreeNodes'), true);
        if (!is_array($rows)) {
            return false;
        }
        $changed = false;
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (['VariableID', 'TargetID'] as $field) {
                if (!isset($row[$field]) || (int) $row[$field] <= 0) {
                    $row[$field] = 1;
                    $changed = true;
                }
            }
        }
        unset($row);
        if (!$changed) {
            return false;
        }
        IPS_SetProperty($this->InstanceID, 'TreeNodes', json_encode($rows, JSON_UNESCAPED_UNICODE));
        // ApplyChanges is called again to make the migrated property authoritative.
        IPS_ApplyChanges($this->InstanceID);
        return true;
    }

    private function ReadConfiguredRows(): array
    {
        $treeRows = json_decode($this->ReadPropertyString('TreeNodes'), true);
        if (!is_array($treeRows) || !array_is_list($treeRows)) {
            throw new InvalidArgumentException('Invalid native Tree configuration.');
        }
        // [] intentionally means an empty tree, not a legacy fallback.
        return $this->NativeTreeRowsToFlatRows($treeRows);
    }

    private function NativeTreeRowsToFlatRows(array $rows): array
    {
        // The native Tree stores parent relations independently of row order.
        // Rebuild preorder explicitly: drag/drop may move an entire subtree.
        $byID = [];
        $children = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Invalid native Tree row.');
            }
            $id = (int) ($row['id'] ?? 0);
            $parent = (int) ($row['parent'] ?? 0);
            if ($id <= 0 || isset($byID[$id])) {
                throw new InvalidArgumentException('Invalid or duplicate native Tree row ID.');
            }
            $byID[$id] = $row;
            $children[$parent][] = $id;
        }
        foreach ($byID as $id => $row) {
            $parent = (int) ($row['parent'] ?? 0);
            if ($parent !== 0 && !isset($byID[$parent])) {
                throw new InvalidArgumentException('Native Tree references a missing parent.');
            }
            if ($parent === $id) {
                throw new InvalidArgumentException('Native Tree node cannot parent itself.');
            }
        }

        $result = [];
        $visited = [];
        $visit = function (int $id, int $level) use (&$visit, &$result, &$visited, $byID, $children): void {
            if (isset($visited[$id])) {
                throw new InvalidArgumentException('Cycle in native Tree.');
            }
            $visited[$id] = true;
            $flat = ['Level' => $level];
            foreach (self::NODE_FIELDS as $key) {
                if (array_key_exists($key, $byID[$id])) {
                    $flat[$key] = $byID[$id][$key];
                }
            }
            $result[] = $flat;
            foreach ($children[$id] ?? [] as $childID) {
                $visit($childID, $level + 1);
            }
        };
        foreach ($children[0] ?? [] as $id) {
            $visit($id, 1);
        }
        if (count($visited) !== count($byID)) {
            throw new InvalidArgumentException('Native Tree contains an unreachable node or cycle.');
        }
        return $result;
    }

    private function FlatRowsToNativeTreeRows(array $rows): array
    {
        $result = [];
        $idsByLevel = [];
        $nextID = 1;
        foreach ($rows as $row) {
            $level = max(1, (int) ($row['Level'] ?? 1));
            $id = $nextID++;
            $entry = [
                'id'     => $id,
                'parent' => $level > 1 ? (int) ($idsByLevel[$level - 1] ?? 0) : 0
            ];
            foreach (self::NODE_FIELDS as $key) {
                if (array_key_exists($key, $row)) {
                    $entry[$key] = $row[$key];
                }
            }
            $entry['VariableID'] = max(1, (int) ($entry['VariableID'] ?? 1));
            $entry['TargetID'] = max(1, (int) ($entry['TargetID'] ?? 1));
            $result[] = $entry;

            $idsByLevel = array_filter($idsByLevel, static fn($key) => $key < $level, ARRAY_FILTER_USE_KEY);
            $idsByLevel[$level] = $id;
        }
        return $result;
    }

    private function ValidateRows(array $rows, array &$warnings): ?string
    {
        $stack = [];
        $previousLevel = 0;
        $seen = [];
        foreach ($rows as $index => $row) {
            $number = $index + 1;
            if (!is_array($row)) {
                return sprintf($this->Translate('Node %d has an invalid configuration.'), $number);
            }
            $level = (int) ($row['Level'] ?? 0);
            $id = trim((string) ($row['ID'] ?? ''));
            $name = trim((string) ($row['Name'] ?? ''));

            if ($level < 1) {
                return sprintf($this->Translate('Node %d: Level must be at least 1.'), $number);
            }
            if ($index === 0 && $level !== 1) {
                return $this->Translate('The first node must have level 1.');
            }
            if ($previousLevel > 0 && $level > $previousLevel + 1) {
                return sprintf($this->Translate('Node %d: Level may only increase by one.'), $number);
            }
            if ($id === '') {
                return sprintf($this->Translate('Node %d has no ID.'), $number);
            }
            if ($name === '') {
                return sprintf($this->Translate('Node %d has no name.'), $number);
            }

            // json_encode keeps IDs containing '/' from colliding with deeper paths.
            $siblingKey = json_encode([array_slice($stack, 0, $level - 1), $id]);
            if (isset($seen[$siblingKey])) {
                return sprintf($this->Translate('Node %d: ID "%s" is duplicated within the same parent.'), $number, $id);
            }
            $seen[$siblingKey] = true;
            $stack = array_slice($stack, 0, $level - 1);
            $stack[] = $id;
            $previousLevel = $level;

            $targetID = max(1, (int) ($row['TargetID'] ?? 1));
            if ($targetID > 1 && !IPS_ObjectExists($targetID)) {
                $warnings[] = sprintf($this->Translate('Node "%s": TargetID %d does not exist and is ignored.'), $name, $targetID);
            }
            $variableID = max(1, (int) ($row['VariableID'] ?? 1));
            if ($variableID > 1 && !$this->VariableExists($variableID)) {
                $warnings[] = sprintf($this->Translate('Node "%s": VariableID %d does not exist and is ignored.'), $name, $variableID);
            }
        }
        return null;
    }

    private function ConfigRowsToTree(array $rows): array
    {
        $tree = [];
        $paths = [];
        $positions = [];
        foreach ($rows as $row) {
            $level = (int) $row['Level'];
            $parentPath = $level === 1 ? [] : ($paths[$level - 1] ?? []);
            $parentKey = json_encode($parentPath);
            $positions[$parentKey] = ($positions[$parentKey] ?? 0) + 100;

            $node = [
                'ID'         => trim((string) $row['ID']),
                'Name'       => trim((string) $row['Name']),
                'VariableID' => max(1, (int) ($row['VariableID'] ?? 1)),
                'TargetID'   => max(1, (int) ($row['TargetID'] ?? 1))
            ];
            $icon = trim((string) ($row['Icon'] ?? ''));
            if ($icon !== '') {
                $node['Icon'] = $icon;
            }
            $background = $this->NormalizeBackground($row['Background'] ?? -1);
            if ($background >= 0) {
                $node['Background'] = $background;
            }
            $node['Position'] = $positions[$parentKey];
            $node['Children'] = [];
            $this->InsertNodeAtPath($tree, $parentPath, $node);

            // Keep numeric depth keys intact. array_slice() without preserve_keys
            // renumbers [1,2,3,...] to [0,1,2,...] and corrupts parent paths.
            $paths = array_slice($paths, 0, $level, true);
            $paths[$level] = array_merge($parentPath, [$node['ID']]);
        }
        return $tree;
    }

    private function TreeToConfigRows(array $tree, int $level, array &$rows): void
    {
        foreach ($tree as $node) {
            $rows[] = [
                'Level'      => $level,
                'ID'         => (string) ($node['ID'] ?? ''),
                'Name'       => (string) ($node['Name'] ?? ''),
                'VariableID' => max(1, (int) ($node['VariableID'] ?? 1)),
                'Icon'       => (string) ($node['Icon'] ?? ''),
                'Background' => (int) ($node['Background'] ?? -1),
                'TargetID'   => max(1, (int) ($node['TargetID'] ?? 1))
            ];
            $this->TreeToConfigRows($node['Children'] ?? [], $level + 1, $rows);
        }
    }

    // ---------------------------------------------------------------------
    // Tree helpers
    // ---------------------------------------------------------------------

    private function NormalizePath(array $path): array
    {
        // Node IDs are stored as trimmed strings; [1, 2] must address the same node as ['1', '2'].
        return array_map(static fn($id): string => trim((string) $id), array_values($path));
    }

    private function IsPathPrefix(array $prefix, array $path): bool
    {
        return count($prefix) <= count($path) && array_slice($path, 0, count($prefix)) === $prefix;
    }

    private function FindNode(array $tree, array $path): ?array
    {
        if ($path === []) {
            return null;
        }
        $id = (string) array_shift($path);
        $index = $this->FindSiblingIndex($tree, $id);
        if ($index === null) {
            return null;
        }
        return $path === [] ? $tree[$index] : $this->FindNode($tree[$index]['Children'] ?? [], $path);
    }

    private function FindSiblingIndex(array $siblings, string $id): ?int
    {
        foreach ($siblings as $index => $node) {
            if ((string) ($node['ID'] ?? '') === $id) {
                return $index;
            }
        }
        return null;
    }

    private function GetChildrenFromTree(array $tree, array $parentPath): ?array
    {
        if ($parentPath === []) {
            return $tree;
        }
        $node = $this->FindNode($tree, $parentPath);
        return $node === null ? null : ($node['Children'] ?? []);
    }

    private function MaxPosition(array $siblings): int
    {
        $max = 0;
        foreach ($siblings as $node) {
            $max = max($max, (int) ($node['Position'] ?? 0));
        }
        return $max;
    }

    private function InsertNodeAtPath(array &$tree, array $parentPath, array $node): bool
    {
        if ($parentPath === []) {
            $tree[] = $node;
            return true;
        }
        return $this->UpdateNodeAtPath($tree, $parentPath, function (array &$parent) use ($node): bool {
            $parent['Children'][] = $node;
            return true;
        });
    }

    private function RemoveNodeAtPath(array &$tree, array $path, ?array &$removed): bool
    {
        if ($path === []) {
            return false;
        }
        $id = (string) array_pop($path);
        if ($path === []) {
            $index = $this->FindSiblingIndex($tree, $id);
            if ($index === null) {
                return false;
            }
            $removed = $tree[$index];
            array_splice($tree, $index, 1);
            return true;
        }
        return $this->UpdateNodeAtPath($tree, $path, function (array &$parent) use ($id, &$removed): bool {
            $parent['Children'] ??= [];
            return $this->RemoveNodeAtPath($parent['Children'], [$id], $removed);
        });
    }

    private function UpdateNodeAtPath(array &$tree, array $path, callable $callback): bool
    {
        if ($path === []) {
            return false;
        }
        $id = (string) array_shift($path);
        $index = $this->FindSiblingIndex($tree, $id);
        if ($index === null) {
            return false;
        }
        if ($path === []) {
            return $callback($tree[$index]);
        }
        $tree[$index]['Children'] ??= [];
        return $this->UpdateNodeAtPath($tree[$index]['Children'], $path, $callback);
    }

    // ---------------------------------------------------------------------
    // Symcon objects
    // ---------------------------------------------------------------------

    private function RebuildReferences(): void
    {
        // Only VM_UPDATE registrations are removed; IPS_KERNELSTARTED stays registered.
        foreach ($this->GetMessageList() as $senderID => $messages) {
            if (in_array(VM_UPDATE, (array) $messages)) {
                $this->UnregisterMessage((int) $senderID, VM_UPDATE);
            }
        }
        foreach ($this->GetReferenceList() as $id) {
            $this->UnregisterReference($id);
        }

        $selectionID = $this->GetIDForIdent('Selection');
        if ($selectionID > 0) {
            $this->RegisterReference($selectionID);
        }
        $variables = [];
        $targets = [];
        $this->CollectReferencedIDs($this->GetActiveTree(), $variables, $targets);
        foreach (array_keys($variables) as $id) {
            $this->RegisterReference($id);
            $this->RegisterMessage($id, VM_UPDATE);
        }
        // Targets are referenced so Symcon shows TreeView as a user of the object.
        foreach (array_keys($targets) as $id) {
            if (!isset($variables[$id])) {
                $this->RegisterReference($id);
            }
        }
    }

    private function CollectReferencedIDs(array $tree, array &$variables, array &$targets): void
    {
        foreach ($tree as $node) {
            $variableID = (int) ($node['VariableID'] ?? 1);
            if ($this->VariableExists($variableID)) {
                $variables[$variableID] = true;
            }
            $targetID = (int) ($node['TargetID'] ?? 1);
            if ($targetID > 1 && IPS_ObjectExists($targetID)) {
                $targets[$targetID] = true;
            }
            $this->CollectReferencedIDs($node['Children'] ?? [], $variables, $targets);
        }
    }

    private function VariableExists(int $id): bool
    {
        // Out-of-range IDs are rejected before IPS_VariableExists is called.
        return $id > 1 && $id <= 60000 && IPS_VariableExists($id);
    }

    private function GetVariableIcon(int $id): string
    {
        if (!$this->VariableExists($id)) {
            return '';
        }
        try {
            return trim((string) (IPS_GetObject($id)['ObjectIcon'] ?? ''));
        } catch (Throwable) {
            return '';
        }
    }

    private function FormatVariable(int $id): string
    {
        if (!$this->VariableExists($id)) {
            return '';
        }
        try {
            return GetValueFormatted($id);
        } catch (Throwable) {
            return (string) GetValue($id);
        }
    }

    private function NormalizeBackground(mixed $value): int
    {
        if (is_int($value)) {
            return ($value >= 0 && $value <= 0xFFFFFF) ? $value : -1;
        }
        if (is_string($value)) {
            $value = trim($value);
            if (preg_match('/^#?([0-9a-fA-F]{6})$/', $value, $matches)) {
                return (int) hexdec($matches[1]);
            }
            if (ctype_digit($value)) {
                $number = (int) $value;
                return ($number >= 0 && $number <= 0xFFFFFF) ? $number : -1;
            }
        }
        return -1;
    }
}
