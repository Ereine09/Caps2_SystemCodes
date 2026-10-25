<?php
require_once __DIR__ . '/../app/config/config.php';

$tables = [];
$columns = $conn->query(
    'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
     ORDER BY TABLE_NAME, ORDINAL_POSITION'
);
while ($column = $columns->fetch_assoc()) {
    $tables[$column['TABLE_NAME']][] = $column;
}

$foreign_keys = [];
$relationships = $conn->query(
    'SELECT DISTINCT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, c.IS_NULLABLE
     FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
     JOIN INFORMATION_SCHEMA.COLUMNS c
       ON c.TABLE_SCHEMA = k.TABLE_SCHEMA
      AND c.TABLE_NAME = k.TABLE_NAME
      AND c.COLUMN_NAME = k.COLUMN_NAME
     WHERE k.TABLE_SCHEMA = DATABASE()
       AND k.REFERENCED_TABLE_NAME IS NOT NULL
     ORDER BY k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME'
);
while ($relationship = $relationships->fetch_assoc()) {
    $foreign_keys[$relationship['TABLE_NAME']][$relationship['COLUMN_NAME']] = $relationship;
}

$groups = [
    'Accounts & Messaging' => [
        'users', 'riders', 'customers', 'customer_addresses', 'customer_login_credentials',
        'activity_logs', 'notifications', 'tbl_messages', 'tbl_user_accounts', 'tbl_active_sessions',
    ],
    'Orders & Payments' => [
        'tbl_orders', 'tbl_order_items', 'tbl_cart', 'tbl_customer_records',
        'tbl_payments', 'tbl_payment_events', 'tbl_vouchers',
    ],
    'Catalog & Loyalty' => [
        'tbl_product_inventory', 'tbl_product_variants', 'tbl_custom_categories',
        'tbl_product_reviews', 'tbl_review_images', 'tbl_review_replies',
        'rewards', 'reward_redemptions', 'loyalty_transactions',
    ],
    'Delivery & Operations' => [
        'delivery_tracking', 'tbl_delivery', 'tbl_deliveries', 'tbl_rider_remittances',
        'tbl_rider_remittance_items', 'tbl_rider_status', 'tbl_remittance_sequences',
        'tbl_ebikes', 'tbl_ebike_usage', 'tbl_ebike_maintenance', 'tbl_suppliers',
        'tbl_supplier_deliveries', 'system_settings',
    ],
];

$group_colors = [
    'Accounts & Messaging' => ['#d9eee7', '#2f755f'],
    'Orders & Payments' => ['#e0ebf8', '#426b9b'],
    'Catalog & Loyalty' => ['#f5e8d6', '#9a6930'],
    'Delivery & Operations' => ['#e9e4f5', '#6f5c9b'],
];

$document = new DOMDocument('1.0', 'UTF-8');
$document->formatOutput = true;
$file = $document->createElement('mxfile');
$file->setAttribute('host', 'app.diagrams.net');
$file->setAttribute('agent', 'DPS live database ERD');
$file->setAttribute('version', '24.7.0');
$file->setAttribute('modified', gmdate('Y-m-d\TH:i:s.000\Z'));
$document->appendChild($file);

$diagram = $document->createElement('diagram');
$diagram->setAttribute('id', 'capstoneDbLiveErd');
$diagram->setAttribute('name', 'Live capstone_db ERD');
$file->appendChild($diagram);

$model = $document->createElement('mxGraphModel');
$model->setAttribute('dx', '1800');
$model->setAttribute('dy', '1200');
$model->setAttribute('grid', '1');
$model->setAttribute('gridSize', '10');
$model->setAttribute('guides', '1');
$model->setAttribute('tooltips', '1');
$model->setAttribute('connect', '1');
$model->setAttribute('arrows', '1');
$model->setAttribute('fold', '1');
$model->setAttribute('page', '1');
$model->setAttribute('pageScale', '1');
$model->setAttribute('pageWidth', '1640');
$model->setAttribute('pageHeight', '2600');
$model->setAttribute('math', '0');
$model->setAttribute('shadow', '0');
$diagram->appendChild($model);

$root = $document->createElement('root');
$model->appendChild($root);
$root->appendChild($document->createElement('mxCell'))->setAttribute('id', '0');
$layer = $document->createElement('mxCell');
$layer->setAttribute('id', '1');
$layer->setAttribute('parent', '0');
$root->appendChild($layer);

$add_vertex = static function (string $id, string $value, string $style, float $x, float $y, float $width, float $height) use ($document, $root): void {
    $cell = $document->createElement('mxCell');
    $cell->setAttribute('id', $id);
    $cell->setAttribute('value', $value);
    $cell->setAttribute('style', $style);
    $cell->setAttribute('vertex', '1');
    $cell->setAttribute('parent', '1');
    $geometry = $document->createElement('mxGeometry');
    $geometry->setAttribute('x', (string) $x);
    $geometry->setAttribute('y', (string) $y);
    $geometry->setAttribute('width', (string) $width);
    $geometry->setAttribute('height', (string) $height);
    $geometry->setAttribute('as', 'geometry');
    $cell->appendChild($geometry);
    $root->appendChild($cell);
};

$add_vertex(
    'erd_title',
    'Darius Poultry Supplies - Live capstone_db ERD',
    'text;html=1;align=left;verticalAlign=middle;whiteSpace=wrap;fontSize=24;fontStyle=1;fontColor=#1f2937;',
    40,
    24,
    1550,
    38
);
$add_vertex(
    'erd_legend',
    '<b>Live schema:</b> ' . count($tables) . ' tables, ' . array_sum(array_map('count', $tables)) . ' columns, ' . array_sum(array_map('count', $foreign_keys)) . ' distinct enforced foreign keys.<br><b>Key:</b> PK primary key, FK foreign key, UQ unique, NN not null, AI auto increment. Solid connectors show enforced relationships; parent-to-child direction uses one-to-many markers.',
    'text;html=1;align=left;verticalAlign=top;whiteSpace=wrap;fontSize=12;fontColor=#4b5563;',
    40,
    68,
    1550,
    56
);

$cell_ids = [];
$table_width = 350;
$column_gap = 55;
$left = 40;
$top = 170;
$group_gap = 34;
$max_bottom = $top;
$group_index = 0;

foreach ($groups as $group_name => $group_tables) {
    $x = $left + $group_index * ($table_width + $column_gap);
    [$header_color, $stroke_color] = $group_colors[$group_name];
    $add_vertex(
        'group_' . $group_index,
        htmlspecialchars($group_name, ENT_QUOTES, 'UTF-8'),
        'rounded=0;whiteSpace=wrap;html=1;align=left;verticalAlign=middle;spacingLeft=10;fontSize=16;fontStyle=1;fillColor=' . $header_color . ';strokeColor=' . $stroke_color . ';fontColor=' . $stroke_color . ';',
        $x,
        $top - 32,
        $table_width,
        26
    );
    $y = $top;

    foreach ($group_tables as $table_name) {
        if (!isset($tables[$table_name])) {
            continue;
        }
        $cell_id = 'table_' . preg_replace('/[^A-Za-z0-9_]/', '_', $table_name);
        $cell_ids[$table_name] = $cell_id;
        $rows = '';
        foreach ($tables[$table_name] as $column) {
            $markers = [];
            if ($column['COLUMN_KEY'] === 'PRI') {
                $markers[] = 'PK';
            }
            if (isset($foreign_keys[$table_name][$column['COLUMN_NAME']])) {
                $markers[] = 'FK';
            }
            if ($column['COLUMN_KEY'] === 'UNI') {
                $markers[] = 'UQ';
            }
            if ($column['IS_NULLABLE'] === 'NO') {
                $markers[] = 'NN';
            }
            if (stripos((string) $column['EXTRA'], 'auto_increment') !== false) {
                $markers[] = 'AI';
            }
            $key_text = $markers ? '<b>' . implode(' ', $markers) . '</b>' : '&nbsp;';
            $column_text = htmlspecialchars($column['COLUMN_NAME'], ENT_QUOTES, 'UTF-8');
            $type_text = htmlspecialchars((string) $column['COLUMN_TYPE'], ENT_QUOTES, 'UTF-8');
            $rows .= '<tr><td width="62" style="border-top:1px solid #e5e7eb;color:#5b6472;font-size:10px;">' . $key_text . '</td><td width="150" style="border-top:1px solid #e5e7eb;font-size:11px;">' . $column_text . '</td><td width="138" style="border-top:1px solid #e5e7eb;color:#6b7280;font-size:10px;">' . $type_text . '</td></tr>';
        }

        $value = '<table style="width:100%;border-collapse:collapse;" cellpadding="4" cellspacing="0">'
            . '<tr><td colspan="3" bgcolor="' . $header_color . '" style="color:' . $stroke_color . ';font-size:14px;"><b>' . htmlspecialchars($table_name, ENT_QUOTES, 'UTF-8') . '</b> <span style="font-size:10px;">(' . count($tables[$table_name]) . ')</span></td></tr>'
            . $rows
            . '</table>';
        $height = 34 + 22 * count($tables[$table_name]);
        $add_vertex(
            $cell_id,
            $value,
            'rounded=0;whiteSpace=wrap;html=1;align=left;verticalAlign=top;spacing=0;strokeColor=' . $stroke_color . ';strokeWidth=1;fillColor=#ffffff;fontColor=#1f2937;',
            $x,
            $y,
            $table_width,
            $height
        );
        $y += $height + $group_gap;
    }
    $max_bottom = max($max_bottom, $y);
    $group_index++;
}

$edge_index = 0;
foreach ($foreign_keys as $child_table => $child_columns) {
    foreach ($child_columns as $column_name => $relationship) {
        $parent_table = $relationship['REFERENCED_TABLE_NAME'];
        if (!isset($cell_ids[$child_table], $cell_ids[$parent_table])) {
            continue;
        }
        $edge = $document->createElement('mxCell');
        $edge->setAttribute('id', 'fk_' . $edge_index++);
        $edge->setAttribute('value', htmlspecialchars($column_name, ENT_QUOTES, 'UTF-8'));
        $edge->setAttribute('style', 'edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;jettySize=auto;html=1;startArrow=ERone;startFill=0;endArrow=' . ($relationship['IS_NULLABLE'] === 'YES' ? 'ERzeroToMany' : 'ERmany') . ';endFill=0;strokeColor=#6b7280;fontColor=#4b5563;fontSize=9;labelBackgroundColor=#ffffff;');
        $edge->setAttribute('edge', '1');
        $edge->setAttribute('parent', '1');
        $edge->setAttribute('source', $cell_ids[$parent_table]);
        $edge->setAttribute('target', $cell_ids[$child_table]);
        $geometry = $document->createElement('mxGeometry');
        $geometry->setAttribute('relative', '1');
        $geometry->setAttribute('as', 'geometry');
        $edge->appendChild($geometry);
        $root->appendChild($edge);
    }
}

$model->setAttribute('pageHeight', (string) max(2600, $max_bottom + 120));
$output_path = __DIR__ . '/../docs/capstone_db_live_erd.drawio';
if ($document->save($output_path) === false) {
    throw new RuntimeException('Could not write the generated draw.io file.');
}
echo 'Generated ' . $output_path . PHP_EOL;
echo count($tables) . ' tables, ' . array_sum(array_map('count', $tables)) . ' columns, ' . $edge_index . ' distinct FK edges.' . PHP_EOL;