# TreeView for IP-Symcon

**Version:** 1.1 · **Status:** Stable (Build 17) · **Prefix:** `TREEV` · **Vendor:** Cassanis Automation

TreeView is a generic HTML visualization tile for arbitrarily deep hierarchical data. Its nodes do not need to mirror the IP-Symcon object tree. Configure nodes with the native Symcon Tree editor, including drag-and-drop reordering and moving entire subtrees to different parents, or build the tree through PHP.

## Features

- Three expand/collapse indicators: boxed plus/minus with connecting lines, plain plus/minus, and chevrons.
- Optional formatted live variable values, icons, RGB background colors, and navigation targets per node.
- Clicking a node updates the global `Selection` variable. An object `TargetID` opens the target locally; a script `TargetID` runs the script on an actual user click (including link nodes), but not on programmatic selection.
- Per-tab, per-instance expanded branches and visual selection using `sessionStorage`, allowing return from a detail view without losing the current location where supported.
- Runs on Symcon 8.1 and newer; fullscreen support via the Symcon HTML SDK requires Symcon 9.1 or newer.
- Automatic support for Symcon Kit icons alongside Font Awesome icons.
- Auto-collapse options: Off, On selection, Always.
- PHP API for runtime changes, persistence, and reset.

## Node configuration

| Field | Description |
| --- | --- |
| `ID` | Node identifier, unique among siblings only |
| `Name` | Visible label |
| `VariableID` | Optional variable whose formatted current value is displayed (`1` = none) |
| `Icon` | Optional explicit icon, overriding the variable's icon |
| `Background` | Optional RGB color (`-1` = none) |
| `TargetID` | Optional Symcon navigation target (`1` = none) |

Object selectors use `1` for “None”. Previously stored `-1` and `0` object IDs are migrated to `1`; the `Background = -1` color sentinel is unchanged.

`VariableID` and `TargetID` are independent. To show a live value and open its details on click, assign the same variable to both. Symcon may provide an archive chart in the variable detail view when the variable is logged.

A node is addressed by its **full ID path**, e.g. `['heating', 'primary', 'flow']`. IDs may repeat under different parents.

## Selection and multiple clients

The automatically created string variable `Selection` contains JSON with `IDPath` and `Node`, or `[]` if there is no selection. It reflects the **last selection across all clients**, not each client's individual selection. Expanded branches and the visual selection are local to each browser tab.

The selection is kept on tree changes, `TREEV_Save()`, `TREEV_ClearRuntimeData()` and when the instance configuration is applied, as long as the selected node exists. If it (or one of its parents) is deleted, the selection is reset to `[]`. `TREEV_Reset()` always clears the selection.

### Client scope

| Action | Affects |
| --- | --- |
| Clicking a node or its expand/collapse indicator | only the client that was clicked |
| `TREEV_SelectNode`, `TREEV_Expand`, `TREEV_Collapse`, `TREEV_ExpandAll`, `TREEV_CollapseAll` | **all** connected clients |
| `TREEV_SelectNodeEx`, `TREEV_ExpandEx`, `TREEV_CollapseEx`, `TREEV_ExpandAllEx`, `TREEV_CollapseAllEx` | only the client with the given client ID (`''` = all clients) |
| Tree changes (`SetData`, `CreateNode`, `Save`, `Reset`, …) | all clients; expanded branches and selection are kept per client |

**Important:** `TREEV_SelectNode()` selects the node on **all** clients. If the node has an object `TargetID`, that object is opened on **all** clients. The same applies to `TREEV_SelectNodeEx()` with an empty client ID.

### Addressing only the clicking client

A script `TargetID` is run on click with these values:

| Variable | Content |
| --- | --- |
| `$_IPS['TREEV_INSTANCE']` | TreeView instance ID (as string) |
| `$_IPS['TREEV_CLIENT']` | ID of the client that was clicked |
| `$_IPS['TREEV_PATH']` | `IDPath` of the clicked node as JSON |

With the client ID, the `…Ex` functions change only that client:

```php
<?php
$instance = (int) $_IPS['TREEV_INSTANCE'];
$client = $_IPS['TREEV_CLIENT'] ?? '';

// After the click, open another node only on the clicking client
TREEV_ExpandEx($instance, $client, ['heating']);
TREEV_SelectNodeEx($instance, $client, ['heating', 'flow']);
```

The client ID is valid per page load of the tile; a reload creates a new ID. Scripts not triggered by a click in TreeView (timers, events, other tiles) have no client ID, because Symcon does not tell a script which client triggered it.

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
TREEV_Reset($instance); // discard unsaved changes and the selection
```

Additional public functions: `TREEV_NodeExists`, `TREEV_CreateNode`, `TREEV_DeleteNode`, `TREEV_SetNodeParent`, `TREEV_SetNodePosition`, `TREEV_SetNodeName`, `TREEV_SetNodeVariable`, `TREEV_SetNodeIcon`, `TREEV_SetNodeBackground`, `TREEV_SetNodeTarget`, `TREEV_GetSelectedNode`, `TREEV_Expand`, `TREEV_Collapse`, `TREEV_ExpandAll`, `TREEV_CollapseAll`, `TREEV_SelectNodeEx`, `TREEV_ExpandEx`, `TREEV_CollapseEx`, `TREEV_ExpandAllEx`, `TREEV_CollapseAllEx`. Paths may contain numbers; `[1, 2]` and `['1', '2']` address the same node.

If a configured variable or target object is deleted later, the instance stays valid: the reference is treated as “None” and shown as a warning in the configuration form.

`TREEV_SetData()` takes an **array of root nodes**, even for a single root. Nodes may contain `Children` arrays and optional `Position` values. Siblings are sorted by Position, then Name and ID. `TREEV_MergeData()` merges one or more subtrees under the supplied parent path (`[]` means root):

- **New nodes** (ID not yet present among the siblings) are inserted; `Name` is required. Without `Position` they are appended after the existing siblings.
- **Existing nodes** (same ID among the same siblings) are updated: only the supplied fields (`Name`, `Position`, `VariableID`, `Icon`, `Background`, `TargetID`) change, and `Children` are merged recursively by the same rules. Fields and children not supplied stay unchanged. `Icon = ''` or `Background = -1` remove the value.

```php
// Rename 'flow' and add a new node below it
TREEV_MergeData($instance, [
    ['ID' => 'flow', 'Name' => 'Primary flow', 'Children' => [
        ['ID' => 'temp', 'Name' => 'Temperature', 'VariableID' => 12345]
    ]]
], ['heating']);
```

API mutations affect runtime state until `TREEV_Save()` is called. Applying the instance configuration also restores the configured tree. An empty tree is supported. Two functions discard unsaved changes:

| Function | Runtime changes | Selection (`Selection`) |
| --- | --- | --- |
| `TREEV_ClearRuntimeData()` | discarded | kept as long as the node exists in the configured tree |
| `TREEV_Reset()` | discarded | always cleared |

## Limitations

- The global Selection variable is not client-specific.
- Browser storage availability and persistence depend on the visualization environment.
- `openObject()` and the appearance of object detail views are controlled by Symcon; TreeView does not render archive charts itself.
- TreeView is not an access-control mechanism.

## Support

This module is free of charge. Donations to support the author are gratefully
accepted: PayPal to ueli.schweizer@cassanis.fr

## Verification

Configuration, subtree drag-and-drop, variable navigation with and without archive/action scripts, independent browser-window state, and a 41-pass basic API test have been tested. The strict API test completed with 67 PASS, 0 FAIL (108 successful checks in total). Please report bugs with reproduction steps, Symcon version, and relevant debug output.

## Release note

**Version 1.1, Build 17 (Stable):**
- New functions `TREEV_SelectNodeEx`, `TREEV_ExpandEx`, `TREEV_CollapseEx`, `TREEV_ExpandAllEx` and `TREEV_CollapseAllEx` affect a single client; target scripts receive `TREEV_INSTANCE`, `TREEV_CLIENT` and `TREEV_PATH` for this.
- The selection is kept on `TREEV_Save()`, `TREEV_ClearRuntimeData()` and when the configuration is applied; it is only cleared when the node or one of its ancestors is deleted.
- `TREEV_MergeData()` updates existing nodes instead of only inserting new ones.
- Deleted variables or targets no longer invalidate the instance; they are shown as a warning in the configuration form.
- The top spacing adapts to whether a tile title is shown.
- Rendering no longer uses CSS `:has()`, so connector lines also work in older browsers.
- Messages translated, stale translations removed, code restructured for readability.

**Build 16 (Beta):** Fullscreen support on Symcon 9.1+, user-click script execution via `TargetID`, object-ID default `1` with migration from `-1`/`0`, improved leaf-node indentation, and corrected plus/minus connector lines.

**Build 15 (Beta):** Support for Symcon Kit icons (`fa-kit`) alongside Font Awesome; `Transparent` and empty icons reserve no space. Improved tree indentation and metadata compatibility set to Symcon 8.1.

**Build 14 (Beta):** Rejects out-of-range `VariableID` values before calling Symcon existence checks; updated release metadata and documentation.

Native Tree drag-and-drop requires a Symcon/Ninja version containing the `changeOrder` fix. If dragging fails, update Ninja first.
