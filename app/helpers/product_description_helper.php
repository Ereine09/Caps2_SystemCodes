<?php
/**
 * Auto-generated product descriptions (Products page: the Generate button, and any product saved
 * with an empty Description).
 *
 * product_description_generate() asks Google Gemini (the same API key as the Analytics AI tip) for a
 * short, factual description built only from what the admin entered - name, categories, brand,
 * weight, price, sizes/flavors. If the AI is not configured, slow or refuses, it falls back to a
 * plain template, so a description is always produced.
 */

if (!function_exists('product_description_input')) {
    /** Normalises the product fields used for a description (same names as the product forms). */
    function product_description_input(array $src): array
    {
        $clean = static fn($v) => trim(preg_replace('/\s+/', ' ', strip_tags((string) $v)));
        $cats = [];
        foreach (['product_category_input', 'food_type_category', 'lifestage_category', 'lifestage_custom', 'health_needs_category', 'health_needs_custom'] as $k) {
            if (($v = $clean($src[$k] ?? '')) !== '') {
                $cats[] = $v;
            }
        }
        $brand = $clean(($src['brand_custom'] ?? '') !== '' ? $src['brand_custom'] : ($src['brand_category'] ?? ''));
        $variants = [];
        foreach ((array) ($src['variant_size'] ?? []) as $v) {
            if (($v = $clean($v)) !== '') {
                $variants[] = $v;
            }
        }
        return [
            'name'       => mb_substr($clean($src['name'] ?? ''), 0, 150),
            'categories' => array_values(array_unique($cats)),
            'brand'      => mb_substr($brand, 0, 80),
            'price'      => max(0, (float) ($src['price'] ?? 0)),
            'weight_kg'  => max(0, (float) ($src['weight_kg'] ?? 0)),
            'variants'   => array_slice(array_values(array_unique($variants)), 0, 12),
        ];
    }
}

if (!function_exists('product_description_template')) {
    function product_description_template(array $p): string
    {
        $name = $p['name'] !== '' ? $p['name'] : 'This product';
        $cats = $p['categories'] ? implode(', ', $p['categories']) : '';
        $text = $name . ($p['brand'] !== '' && stripos($name, $p['brand']) === false ? ' by ' . $p['brand'] : '')
            . ($cats !== '' ? ' is part of our ' . $cats . ' range' : ' is available') . ' at Darius Poultry Supplies.';
        if ($p['variants']) {
            $text .= ' Available in ' . implode(', ', $p['variants']) . '.';
        }
        if ($p['weight_kg'] > 0) {
            $text .= ' Net weight: ' . rtrim(rtrim(number_format($p['weight_kg'], 3, '.', ''), '0'), '.') . ' kg.';
        }
        if ($p['price'] > 0 && !$p['variants']) {
            $text .= ' Price: ₱' . number_format($p['price'], 2) . '.';
        }
        $text .= ' Store in a cool, dry place and check the best-before date on the pack. Ask our staff if you need feeding or usage advice.';
        return $text;
    }
}

if (!function_exists('product_description_ai')) {
    /** Gemini text, or null when it is not available. */
    function product_description_ai(array $p): ?string
    {
        if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '' || !function_exists('curl_init')) {
            return null;
        }
        $facts = "Product name: {$p['name']}\n"
            . ($p['brand'] !== '' ? "Brand: {$p['brand']}\n" : '')
            . ($p['categories'] ? 'Categories: ' . implode(', ', $p['categories']) . "\n" : '')
            . ($p['variants'] ? 'Sizes / flavors: ' . implode(', ', $p['variants']) . "\n" : '')
            . ($p['weight_kg'] > 0 ? "Weight: {$p['weight_kg']} kg\n" : '')
            . ($p['price'] > 0 ? 'Price: PHP ' . number_format($p['price'], 2) . "\n" : '');
        $prompt = "Write a product description for an online shop listing at Darius Poultry Supplies, a poultry and pet supply store in Caloocan, Philippines.\n\n"
            . $facts
            . "\nRules: 2 to 3 sentences, 40 to 70 words, plain text only (no markdown, no bullet points, no quotation marks, no emojis). "
            . "Say what the product is and who or what it is for, based only on the facts above and the product name. "
            . "Do not invent ingredients, nutrient percentages, medical claims, dosages, awards or guarantees. "
            . "For medicines, only say to follow the label or a veterinarian's advice. Money only in Philippine Pesos (PHP or ₱). Friendly, simple English.";
        $data = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.6, 'maxOutputTokens' => 300],
        ];
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent?key=' . GEMINI_API_KEY);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => false, // same setting as gemini_helper.php (XAMPP has no CA bundle)
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code !== 200) {
            error_log('Product description AI failed (HTTP ' . $code . ')');
            return null;
        }
        $text = json_decode($raw, true)['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $text = trim(preg_replace('/\s+/', ' ', str_replace(['**', '*', '#', '"'], '', html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        if (mb_strlen($text) < 30 || preg_match('/\$|USD/', $text)) {
            return null;
        }
        return mb_substr($text, 0, 1000);
    }
}

if (!function_exists('product_description_generate')) {
    /** ['text' => description, 'source' => 'ai' | 'template']. */
    function product_description_generate(array $src): array
    {
        $p = product_description_input($src);
        $ai = $p['name'] !== '' ? product_description_ai($p) : null;
        return $ai !== null ? ['text' => $ai, 'source' => 'ai'] : ['text' => product_description_template($p), 'source' => 'template'];
    }
}
