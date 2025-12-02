<?php

function validateLLMResponse($responseContent) {
    // Define error strings that should trigger a retry
    $errorStrings = [
        "do not roleplay",
        "role-playing",
        "roleplay",
        "sexual content",
        "I'm sorry, but",
        'this type of roleplay',
        'roleplay interaction',
        "do not engage in roleplay",
        "do not engage with roleplay",
        "do not feel comfortable",
        "generating content",
        "respectful interaction",
        "appropriate bounds",
        "cannot roleplay",
        "don't roleplay",
        "don't engage in roleplay",
        "will not roleplay",
        "generate sexual",
        "explicit acts",
        "family-friendly",
        "family friendly",
        "type of content",
        "I am to keep interactions",
        "nsfw",
        'do not generate',
        'respectful and appropriate',
        'non-consensual',
        'aim to engage',
        'ethical interactions',
        'do not wish',
        'generate response',
        'involving the themes',
        'response declined',
        'engage with themes',
        'may be inappropriate',
        'tasteful and appropriate',
        'type of response',
        'i am to keep',
        'Provider returned error'
    ];

    // Check if response contains any error strings
    foreach ($errorStrings as $errorString) {
        if (stripos($responseContent, $errorString) !== false) {
            minai_log("info", "validateLLMResponse: Detected error string '$errorString'");
            return false;
        }
    }
    
    return true;
}

function StripGagAsterisks($text) {
    // Only strip asterisks if player is gagged
    if (!HasEquipmentKeyword($GLOBALS["PLAYER_NAME"], "zad_DeviousGag") && 
        !HasEquipmentKeyword($GLOBALS["PLAYER_NAME"], "zad_DeviousGagPanel") && 
        !HasEquipmentKeyword($GLOBALS["PLAYER_NAME"], "zad_DeviousGagLarge")) {
        return $text;
    }

    // Find all text wrapped in asterisks
    preg_match_all('/\*([^*]+)\*/', $text, $matches);
    
    if (empty($matches[0])) {
        return $text;
    }
    
    // Find the shortest match
    $shortestLength = PHP_INT_MAX;
    $shortestMatch = '';
    foreach ($matches[1] as $i => $innerText) {
        $length = strlen(trim($innerText));
        if ($length < $shortestLength) {
            $shortestLength = $length;
            $shortestMatch = $matches[0][$i];
        }
    }
    
    // Only strip asterisks from the shortest match if it looks like gagged speech
    // (contains m, n, h, or u sounds)
    if (preg_match('/[mnhu]/i', $shortestMatch)) {
        $stripped = trim($shortestMatch, '*');
        return str_replace($shortestMatch, $stripped, $text);
    }
    
    return $text;
}

/**
 * Makes a call to the LLM using OpenRouter
 * 
 * @param array $messages Array of message objects with 'role' and 'content'
 * @param string|null $model Optional model override
 * @param array $options Optional parameters like temperature, max_tokens
 * @return string|null Returns the LLM response content or null on failure
 */
function callLLM($messages, $model = null, $options = []) {
    try {
        $timestamp = date('Y-m-d\TH:i:sP');
        $promptLog = $timestamp . "\n";
        foreach ($messages as $message) {
            $promptLog .= $message['content'] . "\n\n";
        }
        $promptLog .= "\n";
        
        // --- 1. Log Prompt (Legacy MinAI Log) ---
        file_put_contents('/var/www/html/HerikaServer/log/minai_context_sent_to_llm.log', $promptLog, FILE_APPEND);
        minai_log("info", "callLLM: Initiating via Herika Core (fast_request)");

        // --- 2. Initialize Herika Core Connector ---
        global $enginePath;
        // Ensure we have the path. MinAI context might differ, so we use __DIR__ relative path fallback if global not set
        $root = $GLOBALS['herikaPath'] ?? "/var/www/html/HerikaServer/";
        
        require_once($root . "lib/model_dynmodel.php");
        require_once($root . "lib/core/llm_connector.class.php");

        // Determine config (Support Herika 2.2+ structure)
        // If we are inside a request that already set this, use it. Otherwise fetch default/current.
        if (isset($GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"])) {
            $connectorConfig = $GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"];
        } else {
            $connectorConfig = DMgetCurrentModel();
            // Legacy fallback: if it returned a string (driver name), we might need to load specific config
            // But commonly in 2.2+, the file contains the full JSON config.
            // If it's just a string, getConnector usually handles it by name in legacy logic or we construct a basic array.
            if (is_string($connectorConfig)) {
                 $connector = new LLMConnector();
                 // Try to find ID or just pass the string if getConnector supports it? 
                 // Actually LLMConnector::getConnector expects an array with 'driver'. 
                 // Let's assume standard 2.2+ behavior returns array. 
                 // If strictly string, we mock the array.
                 $connectorConfig = ["driver" => $connectorConfig]; 
            }
        }

        $connectorFactory = new LLMConnector();
        $connectorFactory->setOldGlobals($connectorConfig); 
        $driver = $connectorFactory->getConnector($connectorConfig);

        if (!$driver) {
            throw new Exception("Failed to instantiate LLM driver.");
        }

        // --- 3. Prepare Options for fast_request ---
        $requestOptions = [];
        if (isset($options['max_tokens'])) {
            $requestOptions['MAX_TOKENS'] = intval($options['max_tokens']);
        }
        if ($model) {
            $requestOptions['model'] = $model;
        }
        // Map temperature if present
        if (isset($options['temperature'])) {
             // Note: fast_request might read from GLOBALS['CONNECTOR'][...], but some drivers 
             // allow passing custom params in the $customParms array (2nd arg).
             // The standard OpenAI driver's open() method checks $customParms['MAX_TOKENS'], 
             // but often reads temp from globals. 
             // However, we can try setting it in globals momentarily or relying on driver specific implementation.
             // For safety/compatibility with fast_request signature, we primarily pass MAX_TOKENS.
        }

        // --- 4. Execute Request ---
        // fast_request($contextData, $customParms, $callName='')
        $responseContent = $driver->fast_request($messages, $requestOptions, "minai_callLLM");

        if ($responseContent === null || $responseContent === false) {
             throw new Exception("Driver returned empty response.");
        }

        // --- 5. Post-Processing (Gag) ---
        $responseContent = StripGagAsterisks($responseContent);

        // --- 6. Log Response (Legacy MinAI Log) ---
        $responseLog = "== $timestamp START\n";
        $responseLog .= $responseContent . "\n";
        $responseLog .= date('Y-m-d\TH:i:sP') . " END\n\n";
        file_put_contents('/var/www/html/HerikaServer/log/minai_output_from_llm.log', $responseLog, FILE_APPEND);

        return $responseContent;

    } catch (Exception $e) {
        minai_log("error", "callLLM Error: " . $e->getMessage());
        minai_log("debug", "callLLM Stack Trace: " . $e->getTraceAsString());
        return null;
    }
}