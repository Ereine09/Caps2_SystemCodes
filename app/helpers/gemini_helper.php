<?php
/**
 * Helper to communicate with Google Gemini AI
 */
function getGeminiBusinessInsight($stats_summary) {
    if (!defined('GEMINI_API_KEY')) {
        return "AI Error: API Key configuration missing.";
    }
    $api_key = GEMINI_API_KEY; 
    
// Naka-v1beta tayo para suportado ang pinakabagong Gemini models na available sa free tier
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent?key=' . $api_key;

    // Default settings para sa system prompt at customer inquiry
    $system_instruction = "You are a helpful business assistant.";
    
    if (isset($stats_summary['context']) && $stats_summary['context'] === 'rider_strategy') {
        $prompt = "As a delivery operations coach, review these private rider performance metrics: " . json_encode($stats_summary) . ". Give one concise, practical strategy to improve delivery performance and earnings. Do not mention private customer data, API keys, or system internals. Keep it to 2 sentences.";
    } elseif (isset($stats_summary['context']) && $stats_summary['context'] === 'customer_inquiry') {
        $persona = $stats_summary['persona'] ?? "A helpful assistant";
        $instructions = $stats_summary['instructions'] ?? "Respond to the customer.";
        
        // FIX 1: I-decode muna ang mensahe ng customer para malinis ang mabasa ni Gemini
        $customer_msg = isset($stats_summary['message']) ? html_entity_decode($stats_summary['message'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : "";

        // Ginawa nating mas pormal ang pag-gabay sa AI
        $system_instruction = "System Name: {$stats_summary['system']}\nPersona: {$persona}\nInstructions: {$instructions}";
        $prompt = "Customer Inquiry: \"{$customer_msg}\"\n\nProvide a short, natural response based on your instructions. Do not use markdown bold symbols like ** or any HTML character entities.";
    } else {
        $prompt = "As a business analyst, look at these customer stats: " . json_encode($stats_summary) . ". Give me a 2-sentence strategy to improve customer retention.";
    }

    // Inayos ang safetySettings (pinalitan ang BLOCK_NONE ng BLOCK_MEDIUM_AND_ABOVE para sa stability)
    $data = [
        "systemInstruction" => [
            "parts" => [
                ["text" => $system_instruction]
            ]
        ],
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt]
                ]
            ]
        ],
        "safetySettings" => [
            ["category" => "HARM_CATEGORY_HARASSMENT", "threshold" => "BLOCK_MEDIUM_AND_ABOVE"],
            ["category" => "HARM_CATEGORY_HATE_SPEECH", "threshold" => "BLOCK_MEDIUM_AND_ABOVE"],
            ["category" => "HARM_CATEGORY_SEXUALLY_EXPLICIT", "threshold" => "BLOCK_MEDIUM_AND_ABOVE"],
            ["category" => "HARM_CATEGORY_DANGEROUS_CONTENT", "threshold" => "BLOCK_MEDIUM_AND_ABOVE"]
        ],
        "generationConfig" => [
            "temperature" => 0.7,
            "maxOutputTokens" => 1000
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        error_log("Gemini cURL Error: " . $err);
        return "Could not connect to Gemini. cURL Error: " . $err;
    }

    $result = json_decode($response, true);

    if ($http_code !== 200) {
        $error_msg = $result['error']['message'] ?? 'Unknown API error';
        error_log("Gemini API Error (HTTP $http_code): " . $response);
        return "AI Error (HTTP $http_code): " . $error_msg;
    }
    
    if (isset($result['error'])) {
        error_log("Gemini API Error Details: " . json_encode($result['error']));
        return "AI Error: " . ($result['error']['message'] ?? 'Unknown API error.');
    }

    if (isset($result['candidates'][0]['finishReason']) && $result['candidates'][0]['finishReason'] === 'SAFETY') {
        error_log("Gemini Safety Block for message: " . ($stats_summary['message'] ?? 'Strategy request'));
        return "AI Error: Blocked by safety filters.";
    }

    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        $ai_text = $result['candidates'][0]['content']['parts'][0]['text'];
        
        // Una, i-decode ang standard HTML entities
        $decoded_text = html_entity_decode($ai_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // PANGMALAKASANG FIX: Kung may pumuslit pa ring raw literal text na "&#039;" o "&quot;", 
        // gagamit tayo ng regex replace para gawin silang totoong bantas bago tuluyang i-return.
        $decoded_text = preg_replace('/&#039;|&#x27;/i', "'", $decoded_text);
        $decoded_text = preg_replace('/&quot;|&#x22;/i', '"', $decoded_text);
        
        return $decoded_text;
    }

    return "AI Error: Response structure unexpected. Raw response: " . substr($response, 0, 100);
}
?>