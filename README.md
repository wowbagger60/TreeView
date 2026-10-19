# TreeView for IP-Symcon

**Version:** 1.0 · **Status:** Beta (Build 15) · **Prefix:** `TREEV` · **Vendor:** Cassanis Automation

TreeView is a generic HTML visualization tile for arbitrarily deep hierarchical data. Its nodes do not need to mirror the IP-Symcon object tree. Configure nodes with the native Symcon Tree editor, including drag-and-drop reordering and moving entire subtrees to different parents, or build the tree through PHP.

## Features

- Three expand/collapse indicators: boxed plus/minus with connecting lines, plain plus/minus, and chevrons.
- Optional formatted live variable values, icons, RGB background colors, and navigation targets per node.
- Clicking a node always updates the global `Selection` variable. When `TargetID` is set, the tile also invokes Symcon's `openObject()` locally to open the target.
- Per-tab, per-instance expanded branches and visual selection using `sessionStorage`, allowing return from a detail view without losing the current location where supported.
- Auto-collapse options: Off, On selection, Always.
- PHP API for runtime changes, persistence, and reset.

## Node configuration

| Field | Description |
| --- | --- |
| `ID` | Node identifier, unique among siblings only |
| `Name` | Visible label |
| `VariableID` | Optional variable whose formatted current value is displayed (`0` = none) |
| `Icon` | Optional explicit icon, overriding the variable's icon |
| `Background` | Optional RGB color (`-1` = none) |
| `TargetID` | Optional Symcon navigation target (`0` or `1` = none) |

`VariableID` and `TargetID` are independent. To show a live value and open its details on click, assign the same variable to both. Symcon may provide an archive chart in the variable detail view when the variable is logged.

A node is addressed by its **full ID path**, e.g. `['heating', 'primary', 'flow']`. IDs may repeat under different parents.

## Selection and multiple clients

The automatically created string variable `Selection` contains JSON with `IDPath` and `Node`, or `[]` if there is no selection. It reflects the **last selection across all clients**, not each client's individual selection. Expanded branches and the visual selection are local to each browser tab. The PHP API `TREEV_SelectNode()` deliberately broadcasts selection to connected visualization clients; clicking a tile navigates only that client.

## API example

```php
$instance = 12345;
TREEV_SetData($instance, [
    ['ID' => 'heating', 'Name' => 'Heating', 'Children' => [
        ['ID' => 'flow', 'Name' => 'Flow', 'VariableID' => 12345]
    ]]
]);
TREEV_MergeData($instance, [['ID'=>'return','Name'=>'Return']], ['heating']);
$tree = TREEV_GetData($instance);
$node = TREEV_GetNode($instance, ['heating', 'flow']);
$children = TREEV_GetChildren($instance, ['heating']);
TREEV_SelectNode($instance, ['heating', 'flow']);
TREEV_Save($instance);  // persist runtime changes
TREEV_Reset($instance); // discard unsaved changes
```

Additional public functions: `TREEV_NodeExists`, `TREEV_CreateNode`, `TREEV_DeleteNode`, `TREEV_SetNodeParent`, `TREEV_SetNodePosition`, `TREEV_SetNodeName`, `TREEV_SetNodeVariable`, `TREEV_SetNodeIcon`, `TREEV_SetNodeBackground`, `TREEV_SetNodeTarget`, `TREEV_GetSelectedNode`, `TREEV_Expand`, `TREEV_Collapse`, `TREEV_ExpandAll`, `TREEV_CollapseAll`.

`TREEV_SetData()` takes an **array of root nodes**, even for a single root. Nodes may contain `Children` arrays and optional `Position` values. Siblings are sorted by Position, then Name and ID. `TREEV_MergeData()` inserts one or more subtrees under the supplied parent path (`[]` means root) and rejects duplicate sibling IDs.

API mutations affect runtime state until `TREEV_Save()` is called. `TREEV_Reset()` discards unsaved runtime changes. Applying the instance configuration also restores the configured tree. An empty tree is supported.

## Limitations

- The global Selection variable is not client-specific.
- Browser storage availability and persistence depend on the visualization environment.
- `openObject()` and the appearance of object detail views are controlled by Symcon; TreeView does not render archive charts itself.
- TreeView is not an access-control mechanism.

## Beta verification

Configuration, subtree drag-and-drop, variable navigation with and without archive/action scripts, independent browser-window state, and a 41-pass basic API test have been tested. The strict API test completed with 67 PASS, 0 FAIL (108 successful checks in total). Please report bugs with reproduction steps, Symcon version, and relevant debug output.

## Release note

**Build 15 (Beta):** `Transparent` and empty icons no longer reserve space; metadata compatibility updated to Symcon 8.1. Additional native Symcon icons remain under investigation.

**Build 14 (Beta):** Rejects out-of-range `VariableID` values before calling Symcon existence checks; updated release metadata and documentation.

Native Tree drag-and-drop requires a Symcon/Ninja version containing the `changeOrder` fix. If dragging fails, update Ninja first.
