<?php

declare(strict_types=1);

class TreeView extends IPSModuleStrict
{
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
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        try {
            $rows = $this->ReadConfiguredRows();
            $error = $this->ValidateRows($rows);
        } catch (Throwable $e) {
            $rows = [];
            $error = $e->getMessage();
        }
        $this->SendDebug('ApplyChanges', sprintf('Validating %d configured node(s)', count($rows)), 0);
        if ($error !== null) {
            $this->SetBuffer('ConfigurationError', $error);
            $this->SetStatus(201);
            $this->ReloadForm();
            $this->SendDebug('Configuration', $error, 0);
            return;
        }
        $this->SetBuffer('ConfigurationError', '');
        $this->SetStatus(102);
        $this->ReloadForm();

        // ApplyChanges always makes the persisted configuration authoritative.
        // Runtime changes only survive when they have explicitly been saved.
        $this->SetBuffer('RuntimeData', '');
        $this->SetValue('Selection', '[]');
        $this->RebuildReferences();
        $tree = $this->GetActiveTree();
        $this->SendDebug('ApplyChanges', sprintf('Configuration valid: %d root node(s), %d variable reference(s)', count($tree), count($this->GetReferenceList())), 0);
        $this->PushFullUpdate();
    }

    public function GetConfigurationForm(): string
    {
        $content = file_get_contents(__DIR__ . '/form.json');
        if ($content === false) return '{}';
        $form = json_decode($content, true);
        if (!is_array($form)) return '{}';
        $error = $this->GetBuffer('ConfigurationError');
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'ConfigurationError') {
                $element['caption'] = $error;
                $element['visible'] = $error !== '';
            }

        }
        unset($element);
        return json_encode($form, JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/assets/index.html');
        if ($html === false) return '';
        return $html . '<script>window.__treevInstanceID=' . $this->InstanceID . ';handleMessage(' . json_encode($this->BuildFullMessage()) . ');</script>';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
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
        if ($Message === VM_UPDATE) {
            $this->UpdateVisualizationValue(json_encode([
                'type' => 'value',
                'variableID' => $SenderID,
                'value' => $this->FormatVariable($SenderID)
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    public function SetData(array $Data): bool
    {
        $error = $this->ValidateNestedTree($Data);
        if ($error !== null) {
            $this->SendDebug('SetData', $error, 0);
            return false;
        }
        $data = $this->NormalizeTree($Data);
        $this->SetBuffer('RuntimeData', json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->RebuildReferences();
        $this->SendDebug('SetData', sprintf('Runtime tree set: %d root node(s)', count($data)), 0);
        $this->ReconcileSelection($data);
        $this->PushTreeUpdate();
        return true;
    }

    public function MergeData(array $Data, array $ParentIDPath = []): bool
    {
        $error = $this->ValidateNestedTree($Data);
        if ($error !== null) {
            $this->SendDebug('MergeData', $error, 0);
            return false;
        }
        $tree = $this->GetActiveTree();
        if ($ParentIDPath !== [] && $this->FindNode($tree, $ParentIDPath) === null) return false;
        $siblings = $this->GetChildrenFromTree($tree, $ParentIDPath);
        if ($siblings === null) return false;
        $existing = [];
        $max = 0;
        foreach ($siblings as $sibling) {
            $existing[(string)($sibling['ID'] ?? '')] = true;
            $max = max($max, (int)($sibling['Position'] ?? 0));
        }
        foreach ($Data as $node) {
            $id = trim((string)($node['ID'] ?? ''));
            if (isset($existing[$id])) {
                $this->SendDebug('MergeData', 'Duplicate sibling ID "'.$id.'" at target parent', 0);
                return false;
            }
            $existing[$id] = true;
        }
        $incoming = [];
        foreach ($Data as $node) {
            if (!array_key_exists('Position', $node)) { $max += 100; $node['Position'] = $max; }
            $incoming[] = $node;
        }
        $incoming = $this->NormalizeTree($incoming);
        foreach ($incoming as $node) {
            if (!$this->InsertNodeAtPathChecked($tree, $ParentIDPath, $node)) return false;
        }
        return $this->CommitRuntimeTree($tree, 'MergeData');
    }

    public function GetData(): array
    {
        return $this->GetActiveTree();
    }

    public function ClearRuntimeData(): void
    {
        $this->SetBuffer('RuntimeData', '');
        $this->RebuildReferences();
        $this->SendDebug('ClearRuntimeData', 'Runtime tree cleared; configured tree active', 0);
        $this->PushFullUpdate();
    }

    public function GetNode(array $IDPath): array|false
    {
        $node = $this->FindNode($this->GetActiveTree(), $IDPath);
        return $node ?? false;
    }

    public function NodeExists(array $IDPath): bool
    {
        return $this->FindNode($this->GetActiveTree(), $IDPath) !== null;
    }

    public function GetChildren(array $IDPath = []): array|false
    {
        if ($IDPath === []) {
            return $this->GetActiveTree();
        }
        $node = $this->FindNode($this->GetActiveTree(), $IDPath);
        return $node === null ? false : ($node['Children'] ?? []);
    }

    public function CreateNode(array $ParentIDPath, string $ID, string $Name): bool
    {
        $ID = trim($ID); $Name = trim($Name);
        if ($ID === '' || $Name === '') return false;
        $tree = $this->GetActiveTree();
        if ($ParentIDPath !== [] && $this->FindNode($tree, $ParentIDPath) === null) return false;
        if ($this->FindNode($tree, array_merge($ParentIDPath, [$ID])) !== null) return false;
        $siblings = $this->GetChildrenFromTree($tree, $ParentIDPath);
        if ($siblings === null) return false;
        $max = 0; foreach ($siblings as $sibling) $max = max($max, (int)($sibling['Position'] ?? 0));
        $node = ['ID'=>$ID, 'Name'=>$Name, 'Position'=>$max + 100, 'Children'=>[]];
        if (!$this->InsertNodeAtPathChecked($tree, $ParentIDPath, $node)) return false;
        return $this->CommitRuntimeTree($tree, 'CreateNode');
    }

    public function DeleteNode(array $IDPath): bool
    {
        if ($IDPath === []) return false;
        $tree = $this->GetActiveTree();
        $removed = null;
        if (!$this->RemoveNodeAtPath($tree, $IDPath, $removed)) return false;
        return $this->CommitRuntimeTree($tree, 'DeleteNode');
    }

    public function SetNodeParent(array $IDPath, array $ParentIDPath): bool
    {
        if ($IDPath === [] || $IDPath === $ParentIDPath) return false;
        // A node may not be attached below itself. IDPath makes this a simple prefix check.
        if (count($ParentIDPath) >= count($IDPath) && array_slice($ParentIDPath, 0, count($IDPath)) === array_values($IDPath)) return false;
        $tree = $this->GetActiveTree();
        if ($this->FindNode($tree, $IDPath) === null) return false;
        if ($ParentIDPath !== [] && $this->FindNode($tree, $ParentIDPath) === null) return false;
        $removed = null;
        if (!$this->RemoveNodeAtPath($tree, $IDPath, $removed) || $removed === null) return false;
        $siblings = $this->GetChildrenFromTree($tree, $ParentIDPath);
        if ($siblings === null) return false;
        foreach ($siblings as $sibling) if (($sibling['ID'] ?? '') === ($removed['ID'] ?? '')) return false;
        $max = 0; foreach ($siblings as $sibling) $max = max($max, (int)($sibling['Position'] ?? 0));
        $removed['Position'] = $max + 100;
        if (!$this->InsertNodeAtPathChecked($tree, $ParentIDPath, $removed)) return false;
        $newPath = array_merge(array_values($ParentIDPath), [(string)$removed['ID']]);
        return $this->CommitRuntimeTree($tree, 'SetNodeParent', ['from'=>array_values($IDPath), 'to'=>$newPath]);
    }

    public function SetNodePosition(array $IDPath, int $Position): bool
    {
        return $this->UpdateNode($IDPath, function(array &$node) use ($Position): bool { $node['Position']=$Position; return true; }, 'SetNodePosition');
    }

    public function SetNodeName(array $IDPath, string $Name): bool
    {
        $Name=trim($Name); if ($Name==='') return false;
        return $this->UpdateNode($IDPath, function(array &$node) use ($Name): bool { $node['Name']=$Name; return true; }, 'SetNodeName');
    }

    public function SetNodeVariable(array $IDPath, int $VariableID): bool
    {
        if ($VariableID < 0 || $VariableID > 60000) return false;
        if ($VariableID > 0 && !IPS_VariableExists($VariableID)) return false;
        return $this->UpdateNode($IDPath, function(array &$node) use ($VariableID): bool { if ($VariableID>0) $node['VariableID']=$VariableID; else unset($node['VariableID']); return true; }, 'SetNodeVariable');
    }

    public function SetNodeIcon(array $IDPath, string $Icon): bool
    {
        $Icon=trim($Icon);
        return $this->UpdateNode($IDPath, function(array &$node) use ($Icon): bool { if ($Icon!=='') $node['Icon']=$Icon; else unset($node['Icon']); return true; }, 'SetNodeIcon');
    }

    public function SetNodeBackground(array $IDPath, int $Background): bool
    {
        if ($Background < -1 || $Background > 0xFFFFFF) return false;
        return $this->UpdateNode($IDPath, function(array &$node) use ($Background): bool { if ($Background>=0) $node['Background']=$Background; else unset($node['Background']); return true; }, 'SetNodeBackground');
    }

    public function SetNodeTarget(array $IDPath, int $TargetID): bool
    {
        if ($TargetID < 0) return false;
        return $this->UpdateNode($IDPath, function(array &$node) use ($TargetID): bool { if ($TargetID>0) $node['TargetID']=$TargetID; else unset($node['TargetID']); return true; }, 'SetNodeTarget');
    }

    public function SelectNode(array $IDPath): bool
    {
        // API selection: the visualization clients receive the navigation request.
        // Tile clicks use SelectNodeInternal(..., false) and navigate locally instead.
        return $this->SelectNodeInternal($IDPath, true, true);
    }

    private function SelectNodeInternal(array $IDPath, bool $navigate, bool $broadcast): bool
    {
        if ($IDPath === []) {
            $this->SetValue('Selection', '[]');
            if ($broadcast) $this->UpdateVisualizationValue('{"type":"select","path":[]}');
            return true;
        }
        $node = $this->FindNode($this->GetActiveTree(), $IDPath);
        if ($node === null) return false;
        $selection = ['IDPath'=>array_values($IDPath), 'Node'=>$this->NodeForSelection($node)];
        $json = json_encode($selection, JSON_UNESCAPED_UNICODE) ?: '';
        $this->SetValue('Selection', $json);
        $message = ['type'=>'select','path'=>array_values($IDPath)];
        if ($navigate && (int)($node['TargetID'] ?? 0) > 1) {
            $targetID = (int)$node['TargetID'];
            if (IPS_ObjectExists($targetID)) {
                $message['navigateTarget'] = $targetID;
            } else {
                $this->SendDebug('Navigation', 'Target object not found: ' . $targetID, 0);
            }
        }
        if ($broadcast) $this->UpdateVisualizationValue(json_encode($message, JSON_UNESCAPED_UNICODE));
        $this->SendDebug('Selection', $json, 0);
        return true;
    }

    public function GetSelectedNode(): array|false
    {
        $value = (string)$this->GetValue('Selection');
        if ($value === '') return false;
        $selection = json_decode($value, true);
        return is_array($selection) && isset($selection['IDPath'], $selection['Node']) ? $selection : false;
    }

    public function Reset(): void
    {
        $this->SetBuffer('RuntimeData', '');
        $this->SetValue('Selection', '[]');
        $this->RebuildReferences();
        $this->SendDebug('Reset', 'Runtime changes discarded; configured tree restored', 0);
        $this->PushFullUpdate();
    }

    public function Save(): bool
    {
        $tree = $this->GetActiveTree();
        $rows = []; $this->TreeToConfigRows($tree, 1, $rows);
        IPS_SetProperty($this->InstanceID, 'TreeNodes', json_encode($this->FlatRowsToNativeTreeRows($rows), JSON_UNESCAPED_UNICODE));
        $this->SetBuffer('RuntimeData', '');
        IPS_ApplyChanges($this->InstanceID);
        $this->SendDebug('Save', sprintf('Saved %d configured node(s)', count($rows)), 0);
        return true;
    }

    public function Expand(array $IDPath): bool { return $this->PushExpandCommand('expand', $IDPath); }
    public function Collapse(array $IDPath): bool { return $this->PushExpandCommand('collapse', $IDPath); }
    public function ExpandAll(): void { $this->UpdateVisualizationValue('{"type":"expandAll"}'); }
    public function CollapseAll(): void { $this->UpdateVisualizationValue('{"type":"collapseAll"}'); }

    private function PushExpandCommand(string $type, array $path): bool
    {
        if ($this->FindNode($this->GetActiveTree(), $path) === null) return false;
        $this->UpdateVisualizationValue(json_encode(['type'=>$type,'path'=>array_values($path)], JSON_UNESCAPED_UNICODE));
        return true;
    }

    private function PushFullUpdate(): void { $this->UpdateVisualizationValue($this->BuildFullMessage()); }

    private function BuildFullMessage(): string
    {
        $tree = $this->DecorateTree($this->GetActiveTree());
        return json_encode([
            'type'=>'full','tree'=>$tree,
            'design'=>[
                'indicator'=>$this->ReadPropertyString('ExpandIndicator'),
                'indent'=>max(4,$this->ReadPropertyInteger('Indentation')),
                'initialFontScale'=>max(50,min(200,$this->ReadPropertyInteger('InitialFontScale'))),
                'fontScale'=>max(50,min(100,$this->ReadPropertyInteger('FontScale'))),
                'minFontScale'=>max(40,min(200,$this->ReadPropertyInteger('MinimumFontScale'))),
                'autoCollapseMode'=>$this->ReadPropertyString('AutoCollapseMode'),
                'showIcons'=>$this->ReadPropertyBoolean('ShowIcons'),
                'showValues'=>$this->ReadPropertyBoolean('ShowValues')
            ],
            'selectedPath'=>($sel=$this->GetSelectedNode())===false?[]:$sel['IDPath'],
            'emptyText'=>$this->Translate('No nodes configured')
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
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

    private function FlatRowsToNativeTreeRows(array $rows): array
    {
        $result = [];
        $idsByLevel = [];
        $nextID = 1;
        foreach ($rows as $row) {
            $level = max(1, (int)($row['Level'] ?? 1));
            $id = $nextID++;
            $parent = $level > 1 ? (int)($idsByLevel[$level - 1] ?? 0) : 0;
            $entry = ['id'=>$id, 'parent'=>$parent];
            foreach (['ID','Name','VariableID','Icon','Background','TargetID'] as $key) {
                if (array_key_exists($key, $row)) $entry[$key] = $row[$key];
            }
            if (!isset($entry['TargetID'])) $entry['TargetID'] = 1;
            $result[] = $entry;
            $idsByLevel = array_filter($idsByLevel, static fn($k) => $k < $level, ARRAY_FILTER_USE_KEY);
            $idsByLevel[$level] = $id;
        }
        return $result;
    }

    private function NativeTreeRowsToFlatRows(array $rows): array
    {
        // The native Tree stores parent relations independently of row order.
        // Rebuild preorder explicitly: drag/drop may move an entire subtree.
        $byID = [];
        $children = [];
        foreach ($rows as $index => $row) {
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
            $row = $byID[$id];
            $flat = ['Level' => $level];
            foreach (['ID', 'Name', 'VariableID', 'Icon', 'Background', 'TargetID'] as $key) {
                if (array_key_exists($key, $row)) $flat[$key] = $row[$key];
            }
            $result[] = $flat;
            foreach ($children[$id] ?? [] as $childID) $visit($childID, $level + 1);
        };
        foreach ($children[0] ?? [] as $id) $visit($id, 1);
        if (count($visited) !== count($byID)) {
            throw new InvalidArgumentException('Native Tree contains an unreachable node or cycle.');
        }
        return $result;
    }

    private function ValidateRows(array $rows): ?string
    {
        $stack=[]; $previousLevel=0;
        foreach ($rows as $i=>$row) {
            if (!is_array($row)) return sprintf('Node %d has an invalid configuration.', $i+1);
            $level=(int)($row['Level']??0); $id=trim((string)($row['ID']??'')); $name=trim((string)($row['Name']??''));
            if ($level < 1) return sprintf('Node %d: Level must be at least 1.', $i+1);
            if ($i===0 && $level!==1) return 'The first node must have level 1.';
            if ($previousLevel>0 && $level>$previousLevel+1) return sprintf('Node %d: Level may only increase by one.', $i+1);
            if ($id==='') return sprintf('Node %d has no ID.', $i+1);
            if ($name==='') return sprintf('Node %d has no name.', $i+1);
            $parentKey=$level===1?'ROOT':implode('/',array_slice($stack,0,$level-1));
            $siblingKey=$parentKey."\0".$id;
            static $dummy; // no-op, keeps validation intentionally local below
            if (!isset($seen)) $seen=[];
            if (isset($seen[$siblingKey])) return sprintf('Node %d: ID "%s" is duplicated within the same parent.', $i+1,$id);
            $seen[$siblingKey]=true;
            $stack=array_slice($stack,0,$level-1); $stack[]=$id; $previousLevel=$level;
            $vid=(int)($row['VariableID']??0);
            if ($vid>60000 || ($vid>0 && !IPS_VariableExists($vid))) return sprintf('Node %d: VariableID %d does not exist.', $i+1,$vid);
        }
        return null;
    }

    private function ConfigRowsToTree(array $rows): array
    {
        $root=[]; $paths=[]; $counts=[];
        foreach ($rows as $row) {
            $level=(int)$row['Level'];
            $node=['ID'=>trim((string)$row['ID']),'Name'=>trim((string)$row['Name'])];
            foreach (['VariableID','TargetID'] as $k) { $v=(int)($row[$k]??0); if ($v>0) $node[$k]=$v; }
            $icon=trim((string)($row['Icon']??'')); if ($icon!=='') $node['Icon']=$icon;
            $background=$this->NormalizeBackground($row['Background']??-1); if ($background>=0) $node['Background']=$background;
            $parentPath=$level===1?[]:($paths[$level-1]??[]);
            $pk=json_encode($parentPath); $counts[$pk]=($counts[$pk]??0)+1; $node['Position']=$counts[$pk]*100; $node['Children']=[];
            $this->InsertNodeAtPath($root,$parentPath,$node);
            // Keep numeric depth keys intact. array_slice() without preserve_keys
            // renumbers [1,2,3,...] to [0,1,2,...] and corrupts parent paths.
            $paths=array_slice($paths,0,$level,true);
            $paths[$level]=array_merge($parentPath,[$node['ID']]);
        }
        return $root;
    }

    private function InsertNodeAtPath(array &$nodes, array $parentPath, array $node): void
    {
        if ($parentPath===[]) { $nodes[]=$node; return; }
        $id=array_shift($parentPath);
        foreach ($nodes as &$n) if (($n['ID']??'')===$id) { $this->InsertNodeAtPath($n['Children'],$parentPath,$node); return; }
        unset($n);
    }

    private function GetActiveTree(): array
    {
        $runtime=$this->GetBuffer('RuntimeData');
        if ($runtime!=='') { $v=json_decode($runtime,true); if (is_array($v)) return $v; }
        return $this->ConfigRowsToTree($this->ReadConfiguredRows());
    }

    private function ValidateNestedTree(array $tree, array $path=[]): ?string
    {
        $seen=[];
        foreach ($tree as $i=>$node) {
            if (!is_array($node)) return 'Invalid node at '.json_encode($path).'.';
            $id=trim((string)($node['ID']??'')); $name=trim((string)($node['Name']??''));
            if ($id==='') return 'A node has no ID.'; if ($name==='') return 'Node "'.$id.'" has no name.';
            if (isset($seen[$id])) return 'Duplicate sibling ID "'.$id.'".'; $seen[$id]=true;
            $vid=(int)($node['VariableID']??0); if ($vid>60000 || ($vid>0 && !IPS_VariableExists($vid))) return 'VariableID '.$vid.' does not exist.';
            $children=$node['Children']??[]; if (!is_array($children)) return 'Children of "'.$id.'" must be an array.';
            $e=$this->ValidateNestedTree($children,array_merge($path,[$id])); if ($e!==null) return $e;
        }
        return null;
    }

    private function NormalizeTree(array $tree): array
    {
        $out=[]; $auto=0;
        foreach ($tree as $node) {
            $auto+=100; $n=['ID'=>trim((string)$node['ID']),'Name'=>trim((string)$node['Name']),'Position'=>(int)($node['Position']??$auto)];
            foreach (['VariableID','TargetID'] as $k) { $v=(int)($node[$k]??0); if ($v>0) $n[$k]=$v; }
            $icon=trim((string)($node['Icon']??'')); if ($icon!=='') $n['Icon']=$icon;
            $background=$this->NormalizeBackground($node['Background']??-1); if ($background>=0) $n['Background']=$background;
            $n['Children']=$this->NormalizeTree($node['Children']??[]); $out[]=$n;
        }
        usort($out, fn($a,$b)=>($a['Position']<=>$b['Position']) ?: strcasecmp($a['Name'],$b['Name']) ?: strcmp($a['ID'],$b['ID']));
        return $out;
    }

    private function DecorateTree(array $tree): array
    {
        foreach ($tree as &$node) {
            $vid=(int)($node['VariableID']??0);
            $node['Value']=$vid>0?$this->FormatVariable($vid):'';
            $explicitIcon=trim((string)($node['Icon']??''));
            $node['EffectiveIcon']=$explicitIcon!==''?$explicitIcon:$this->GetVariableIcon($vid);
            $node['Children']=$this->DecorateTree($node['Children']??[]);
        }
        unset($node); return $tree;
    }

    private function GetChildrenFromTree(array $tree, array $parentPath): ?array
    {
        if ($parentPath === []) return $tree;
        $node = $this->FindNode($tree, $parentPath);
        return $node === null ? null : ($node['Children'] ?? []);
    }

    private function InsertNodeAtPathChecked(array &$tree, array $parentPath, array $node): bool
    {
        if ($parentPath === []) { $tree[]=$node; return true; }
        $id=(string)array_shift($parentPath);
        foreach ($tree as &$item) {
            if (($item['ID']??'') !== $id) continue;
            if ($parentPath === []) { $item['Children'][]=$node; unset($item); return true; }
            $ok=$this->InsertNodeAtPathChecked($item['Children'], $parentPath, $node); unset($item); return $ok;
        }
        unset($item); return false;
    }

    private function RemoveNodeAtPath(array &$tree, array $path, ?array &$removed): bool
    {
        if ($path === []) return false;
        $id=(string)array_shift($path);
        foreach ($tree as $index=>&$item) {
            if (($item['ID']??'') !== $id) continue;
            if ($path === []) { $removed=$item; array_splice($tree,$index,1); unset($item); return true; }
            $ok=$this->RemoveNodeAtPath($item['Children'], $path, $removed); unset($item); return $ok;
        }
        unset($item); return false;
    }

    private function UpdateNode(array $path, callable $callback, string $debug): bool
    {
        if ($path === []) return false;
        $tree=$this->GetActiveTree();
        if (!$this->UpdateNodeAtPath($tree,$path,$callback)) return false;
        return $this->CommitRuntimeTree($tree,$debug);
    }

    private function UpdateNodeAtPath(array &$tree, array $path, callable $callback): bool
    {
        $id=(string)array_shift($path);
        foreach ($tree as &$item) {
            if (($item['ID']??'') !== $id) continue;
            if ($path === []) { $ok=$callback($item); unset($item); return $ok; }
            $ok=$this->UpdateNodeAtPath($item['Children'],$path,$callback); unset($item); return $ok;
        }
        unset($item); return false;
    }

    private function CommitRuntimeTree(array $tree, string $source, ?array $move = null): bool
    {
        $tree=$this->NormalizeTree($tree);
        $this->SetBuffer('RuntimeData', json_encode($tree, JSON_UNESCAPED_UNICODE));
        $this->RebuildReferences();
        $this->ReconcileSelection($tree, $move);
        $this->PushTreeUpdate($move);
        $this->SendDebug($source, sprintf('Tree updated: %d root node(s)', count($tree)), 0);
        return true;
    }

    private function ReconcileSelection(array $tree, ?array $move = null): void
    {
        $selection = $this->GetSelectedNode();
        if ($selection === false) {
            if ((string)$this->GetValue('Selection') !== '[]') $this->SetValue('Selection', '[]');
            return;
        }
        $path = array_values($selection['IDPath']);
        if ($move !== null) {
            $from = $move['from'] ?? []; $to = $move['to'] ?? [];
            if (count($path) >= count($from) && array_slice($path, 0, count($from)) === $from)
                $path = array_merge($to, array_slice($path, count($from)));
        }
        $node = $this->FindNode($tree, $path);
        if ($node === null) {
            $this->SetValue('Selection', '[]');
            return;
        }
        $this->SetValue('Selection', json_encode(['IDPath'=>$path,'Node'=>$this->NodeForSelection($node)], JSON_UNESCAPED_UNICODE) ?: '[]');
    }

    private function PushTreeUpdate(?array $move = null): void
    {
        $message = json_decode($this->BuildFullMessage(), true);
        if (!is_array($message)) return;
        $message['type'] = 'tree';
        if ($move !== null) $message['move'] = $move;
        $selection = $this->GetSelectedNode();
        $message['selectedPath'] = $selection === false ? [] : $selection['IDPath'];
        $this->UpdateVisualizationValue(json_encode($message, JSON_UNESCAPED_UNICODE));
    }

    private function TreeToConfigRows(array $tree, int $level, array &$rows): void
    {
        foreach ($tree as $node) {
            $rows[]=[
                'Level'=>$level,
                'ID'=>(string)($node['ID']??''),
                'Name'=>(string)($node['Name']??''),
                'VariableID'=>(int)($node['VariableID']??0),
                'Icon'=>(string)($node['Icon']??''),
                'Background'=>(int)($node['Background']??-1),
                'TargetID'=>(int)($node['TargetID']??-1)
            ];
            $this->TreeToConfigRows($node['Children']??[], $level+1, $rows);
        }
    }

    private function NormalizeBackground(mixed $value): int
    {
        if (is_int($value)) return ($value >= 0 && $value <= 0xFFFFFF) ? $value : -1;
        if (is_string($value)) {
            $value=trim($value);
            if ($value==='') return -1;
            if (preg_match('/^#?([0-9a-fA-F]{6})$/',$value,$m)) return hexdec($m[1]);
            if (ctype_digit($value)) { $n=(int)$value; return ($n>=0 && $n<=0xFFFFFF)?$n:-1; }
        }
        return -1;
    }

    private function GetVariableIcon(int $id): string
    {
        if ($id<=0 || $id>60000 || !IPS_VariableExists($id)) return '';
        try {
            $object=IPS_GetObject($id);
            return trim((string)($object['ObjectIcon']??''));
        } catch (Throwable) {
            return '';
        }
    }

    private function FormatVariable(int $id): string
    {
        if ($id<=0 || $id>60000 || !IPS_VariableExists($id)) return '';
        try { return GetValueFormatted($id); } catch (Throwable) { return (string)GetValue($id); }
    }

    private function RebuildReferences(): void
    {
        foreach ($this->GetReferenceList() as $id) { $this->UnregisterMessage($id, VM_UPDATE); $this->UnregisterReference($id); }
        $selectionID = $this->GetIDForIdent('Selection');
        if ($selectionID > 0) {
            $this->RegisterReference($selectionID);
        }
        $ids=[]; $this->CollectVariableIDs($this->GetActiveTree(),$ids);
        foreach (array_keys($ids) as $id) { $this->RegisterReference((int)$id); $this->RegisterMessage((int)$id, VM_UPDATE); }
    }

    private function CollectVariableIDs(array $tree, array &$ids): void
    {
        foreach ($tree as $node) { $id=(int)($node['VariableID']??0); if ($id>0 && $id<=60000 && IPS_VariableExists($id)) $ids[$id]=true; $this->CollectVariableIDs($node['Children']??[],$ids); }
    }

    private function FindNode(array $tree, array $path): ?array
    {
        if ($path===[]) return null; $id=(string)array_shift($path);
        foreach ($tree as $node) if (($node['ID']??'')===$id) return $path===[]?$node:$this->FindNode($node['Children']??[],$path);
        return null;
    }

    private function NodeForSelection(array $node): array
    {
        unset($node['Children'], $node['Value'], $node['EffectiveIcon']); return $node;
    }
}
