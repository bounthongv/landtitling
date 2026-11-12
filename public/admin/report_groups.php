<?php
// public/admin/report_groups.php — group bands and subtotal footers for reports.
//
// A report may declare, in its API payload, how its rows are grouped:
//
//   'group_by' => [['key'=>'province','label'=>'Province'],
//                  ['key'=>'district','label'=>'District'], ...]
//   'break_on' => ['month']        // a change here always starts a new group
//   'subtotal' => ['collected_kip'] // columns summed into each group footer
//
// The rows must already be sorted by the group keys, which the reports do in
// SQL. This file turns that flat list into an ordered list of render items —
// bands, detail rows and subtotals — so the screen and the printed document
// draw the same picture. A report that declares nothing comes back as plain
// detail rows and renders exactly as before.
//
// Grouping only ever compares a row with the one before it. A footer therefore
// can never swallow rows from another province the way a rowspan computed over
// the whole result would (the failure that made an earlier attempt print two
// provinces under one header).

/**
 * Display text for one report cell: group digits on money/count columns, but
 * never on identifiers, years, dates or short codes.
 */
function report_cell_text($cell, string $label): string
{
    $plain = is_array($cell) ? json_encode($cell) : (string)$cell;
    $isNum = !is_array($cell) && is_numeric($plain);
    $skip  = preg_match('/year|^id|no\.|date|status|conf/i', $label);
    if ($isNum && !$skip && (float)$plain >= 10000) {
        $plain = number_format((float)$plain, (strpos($plain, '.') !== false ? 2 : 0));
    }
    return $plain;
}

/**
 * Flatten a report result into printable items.
 *
 * Each item is one of:
 *   ['kind'=>'band',     'level'=>int, 'label'=>string, 'value'=>string]
 *   ['kind'=>'detail',   'row'=>array]
 *   ['kind'=>'subtotal', 'level'=>int, 'label'=>string, 'value'=>string,
 *                        'count'=>int, 'sums'=>[alias=>float], 'pos'=>[alias=>int]]
 */
function report_items(array $data): array
{
    $rows   = $data['rows'] ?? [];
    $items  = [];
    if (!$rows) { return $items; }

    $levels  = $data['group_by'] ?? [];
    $breaks  = $data['break_on'] ?? [];
    $subKeys = $data['subtotal'] ?? [];

    if (!$levels) {
        foreach ($rows as $row) { $items[] = ['kind' => 'detail', 'row' => $row]; }
        return $items;
    }

    // Resolve each subtotal column to its table position. Rows are associative
    // arrays in SELECT order, and the columns list is in the same order.
    $pos = array_flip(array_keys($rows[0]));
    $subPos = [];
    foreach ($subKeys as $sk) {
        if (isset($pos[$sk])) { $subPos[$sk] = (int)$pos[$sk]; }
    }

    $keys = array_column($levels, 'key');
    $n    = count($keys);
    $active = [];   // level => ['value'=>string, 'sums'=>[alias=>float], 'count'=>int]
    $prevBreak = null;

    // Close every footer from $from down to the deepest level still open. A
    // change at a shallow level closes its children first, so totals come out
    // village → district → province.
    $closeFrom = function (int $from) use (&$active, &$items, $levels, $subPos) {
        for ($i = count($levels) - 1; $i >= $from; $i--) {
            if (!isset($active[$i])) { continue; }
            $g = $active[$i];
            unset($active[$i]);
            $items[] = [
                'kind'  => 'subtotal',
                'level' => $i,
                'label' => $levels[$i]['label'],
                'value' => (string)$g['value'],
                'count' => (int)$g['count'],
                'sums'  => $g['sums'],
                'pos'   => $subPos,
            ];
        }
    };

    foreach ($rows as $row) {
        $breakVals = [];
        foreach ($breaks as $b) { $breakVals[$b] = (string)($row[$b] ?? ''); }

        // The first level whose value differs from the row above opens a new
        // group (and everything below it).
        $firstDiff = null;
        for ($i = 0; $i < $n; $i++) {
            $v = (string)($row[$keys[$i]] ?? '');
            if (!isset($active[$i]) || $active[$i]['value'] !== $v) { $firstDiff = $i; break; }
        }
        // A column the report sorts above the location (e.g. the collection
        // month) repeats a village in a later period: that is a new group, not
        // a continuation of the previous footer.
        if ($firstDiff === null && $prevBreak !== null && $breakVals !== $prevBreak) {
            $firstDiff = 0;
        }

        if ($firstDiff !== null) {
            $closeFrom($firstDiff);
            for ($i = $firstDiff; $i < $n; $i++) {
                $val = (string)($row[$keys[$i]] ?? '');
                $items[] = [
                    'kind'  => 'band',
                    'level' => $i,
                    'label' => $levels[$i]['label'],
                    'value' => $val,
                ];
                $active[$i] = ['value' => $val, 'sums' => [], 'count' => 0];
            }
        }

        $items[] = ['kind' => 'detail', 'row' => $row];

        foreach ($active as $i => &$g) {
            $g['count']++;
            foreach ($subKeys as $sk) {
                $g['sums'][$sk] = ($g['sums'][$sk] ?? 0) + (float)($row[$sk] ?? 0);
            }
        }
        unset($g);

        $prevBreak = $breakVals;
    }

    $closeFrom(0);
    return $items;
}
