<?php
// Start session to store chat history, model, and CSRF token
session_start();

// Load API key from separate config file
require_once 'config.php'; // This file should define $api_key (see previous instructions)

// Default system prompt (can be changed via UI)
$default_system_prompt = 'You are an expert coder. Please interpret code snippets accurately and provide detailed responses.';

// Meta instructions for guiding multi-turn responses (appended to every turn)
$meta_instructions = 'You are in a multi-turn reasoning chain powered by xAI Grok. Current turn: {TURN_NUM} of {TOTAL_TURNS}. Original query: "{ORIGINAL_QUERY}". Previous response (if any): "{PREV_RESPONSE}". Reason step-by-step about the query. At the end of your response, include a <system> tag with refined instructions for the next turn (e.g., to deepen analysis or correct errors). Example: <system>Focus on edge cases in the next reasoning step.</system>. Make the next instructions meta-aware and self-improving.';

// Initialize session if not set
if (!isset($_SESSION['chat_history'])) {
$_SESSION['chat_history'] = [
['role' => 'system', 'content' => $default_system_prompt]
];
}
if (!isset($_SESSION['selected_model'])) {
$_SESSION['selected_model'] = 'grok-3'; // Default Grok model
}
if (!isset($_SESSION['csrf_token'])) {
$_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Generate CSRF token for security
}
if (!isset($_SESSION['max_wit'])) {
$_SESSION['max_wit'] = false; // Default: no extra wit
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
// Verify CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
die('CSRF token mismatch. Please try again.');
}

// Handle user message with multi-turn reasoning
if (isset($_POST['user_message']) && !empty(trim($_POST['user_message']))) {
$message = htmlspecialchars(trim($_POST['user_message'])); // Sanitize input
$num_turns = isset($_POST['num_turns']) ? max(1, min(10, intval($_POST['num_turns']))) : 1; // Sanitize: 1-10 turns

// Add the user's query to history once (as the starting point)
$_SESSION['chat_history'][] = ['role' => 'user', 'content' => $message];

$last_system_prompt = get_current_system_prompt(); // Start with current system prompt
$prev_response = ''; // Track previous response for meta injection
for ($turn = 1; $turn <= $num_turns; $turn++) {
// Prepare meta instructions with placeholders replaced
$current_meta = str_replace(
['{TURN_NUM}', '{TOTAL_TURNS}', '{ORIGINAL_QUERY}', '{PREV_RESPONSE}'],
[$turn, $num_turns, $message, $prev_response],
$meta_instructions
);

// Prepare effective history for this turn
$effective_history = [
['role' => 'system', 'content' => $last_system_prompt],
['role' => 'user', 'content' => $message],
['role' => 'system', 'content' => $current_meta] // Append meta instructions
];

// Optionally append wit instruction
if ($_SESSION['max_wit']) {
$effective_history[] = ['role' => 'system', 'content' => 'Respond with maximum wit and humor, like Grok from xAI.'];
}

// Call xAI Grok API
$response = call_grok_api($effective_history, $_SESSION['selected_model'], $api_key);

if ($response) {
// Add full response to chat history
$_SESSION['chat_history'][] = ['role' => 'assistant', 'content' => "Turn $turn: " . $response];

// Parse for <system> tag to get next system prompt
if (preg_match('/<system>(.*?)<\/system>/is', $response, $matches) && !empty($matches[1])) {
$last_system_prompt = trim($matches[1]);
} else {
// Fallback: Use full response if no tag found
$last_system_prompt = $response;
}

$prev_response = $response; // For next meta
} else {
// Error: Add to history and break loop
$_SESSION['chat_history'][] = ['role' => 'system', 'content' => "Error in Turn $turn: Could not get Grok response. Check API key or connection."];
break;
}
}
}

// Handle clear history
if (isset($_POST['clear_history'])) {
$_SESSION['chat_history'] = [['role' => 'system', 'content' => get_current_system_prompt()]];
}

// Handle model selection
if (isset($_POST['selected_model'])) {
$_SESSION['selected_model'] = htmlspecialchars($_POST['selected_model']);
}

// Handle system message change
if (isset($_POST['new_system_prompt']) && !empty(trim($_POST['new_system_prompt']))) {
$new_prompt = htmlspecialchars(trim($_POST['new_system_prompt']));
$_SESSION['chat_history'] = [['role' => 'system', 'content' => $new_prompt]];
}

// Handle max wit toggle
if (isset($_POST['max_wit'])) {
$_SESSION['max_wit'] = $_POST['max_wit'] === 'on';
}

// Regenerate CSRF token after POST
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper function to get current system prompt (first message)
function get_current_system_prompt() {
return $_SESSION['chat_history'][0]['content'] ?? 'Default system prompt.';
}

// Function to call xAI Grok API
function call_grok_api($messages, $model, $api_key) {
$url = 'https://api.x.ai/v1/chat/completions'; // Confirm exact URL in xAI docs
$data = [
'model' => $model,
'messages' => $messages,
'temperature' => 0.7 // Adjust for creativity
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
'Content-Type: application/json',
'Authorization: Bearer ' . $api_key
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
return null; // Handle errors gracefully
}

$result = json_decode($response, true);
return $result['choices'][0]['message']['content'] ?? null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Awesome Grok Chat System</title>
<!-- Bootstrap CSS for awesome styling -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background-color: #f8f9fa; }
.chat-container { max-width: 800px; margin: 20px auto; }
.chat-messages { height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background-color: white; border-radius: 8px; }
.message { margin-bottom: 10px; padding: 8px; border-radius: 5px; }
.user-message { background-color: #d1e7dd; align-self: flex-end; }
.assistant-message { background-color: #f8d7da; }
.system-message { background-color: #fff3cd; font-style: italic; }
.controls { margin-top: 10px; }
</style>
</head>
<body>
<div class="chat-container">
<h1 class="text-center mb-4">Awesome Grok Chat (Powered by xAI)</h1>

<!-- Chat Messages Display -->
<div class="chat-messages" id="chat-messages">
<?php foreach ($_SESSION['chat_history'] as $msg): ?>
<div class="message <?= $msg['role'] === 'user' ? 'user-message' : ($msg['role'] === 'assistant' ? 'assistant-message' : 'system-message') ?>">
<strong><?= ucfirst($msg['role']) ?>:</strong> <?= nl2br(htmlspecialchars($msg['content'])) ?>
</div>
<?php endforeach; ?>
</div>

<!-- Input Form -->
<form method="POST" class="mt-3" id="chat-form">
<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
<div class="input-group">
<input type="text" name="user_message" class="form-control" placeholder="Type your message..." required>
<button type="submit" class="btn btn-primary" id="send-btn">Send</button>
</div>
<div class="row g-2 mt-2">
<div class="col-md-4 form-check">
<input type="checkbox" name="max_wit" class="form-check-input" id="max-wit" <?= $_SESSION['max_wit'] ? 'checked' : '' ?>>
<label class="form-check-label" for="max-wit">Max Wit (Grok-style humor)</label>
</div>
<div class="col-md-4">
<input type="number" name="num_turns" class="form-control" value="1" min="1" max="10" placeholder="Turns">
<small class="form-text text-muted">Number of reasoning turns (1-10)</small>
</div>
<div class="col-md-4">
<small class="form-text text-muted">Responses may include &lt;system&gt; tags for next-turn instructions.</small>
</div>
</div>
</form>

<!-- Controls Section -->
<div class="controls row g-3 mt-3">
<!-- Clear History -->
<div class="col-md-3">
<form method="POST">
<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
<button type="submit" name="clear_history" class="btn btn-warning w-100">Clear History</button>
</form>
</div>

<!-- Model Selector -->
<div class="col-md-3">
<form method="POST">
<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
<select name="selected_model" class="form-select" onchange="this.form.submit()">
<option value="grok-3-mini" <?= $_SESSION['selected_model'] === 'grok-3-mini' ? 'selected' : '' ?>>Grok-3-mini</option>
<option value="grok-3" <?= $_SESSION['selected_model'] === 'grok-3' ? 'selected' : '' ?>>Grok 3 (Standard)</option>
<option value="grok-4" <?= $_SESSION['selected_model'] === 'grok-4' ? 'selected' : '' ?>>Grok-4 (New)</option>
<!-- Add more xAI models as they become available -->
</select>
</form>
</div>

<!-- Change System Message -->
<div class="col-md-3">
<form method="POST">
<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
<textarea name="new_system_prompt" class="form-control" rows="2" placeholder="Edit system prompt..."><?= htmlspecialchars(get_current_system_prompt()) ?></textarea>
<button type="submit" class="btn btn-info w-100 mt-2">Update System Prompt</button>
</form>
</div>

<!-- Inspect System Code (Easter Egg) -->
<div class="col-md-3">
<button type="button" class="btn btn-secondary w-100" data-bs-toggle="modal" data-bs-target="#systemModal">Inspect System Code</button>
</div>
</div>
</div>

<!-- Modal for System Prompt Inspection -->
<div class="modal fade" id="systemModal" tabindex="-1" aria-labelledby="systemModalLabel" aria-hidden="true">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title" id="systemModalLabel">Current System Prompt (The Code Behind the Curtain)</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body">
<pre><?= htmlspecialchars(get_current_system_prompt()) ?></pre>
<p>Interesting observation: This matches the prompt in the code snippet you shared. Meta, right?</p>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
</div>
</div>
</div>
</div>

<!-- Bootstrap JS and Custom Script -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-scroll to bottom of chat
const chatMessages = document.getElementById('chat-messages');
chatMessages.scrollTop = chatMessages.scrollHeight;

// Simple loading indicator (disable button on submit)
const form = document.getElementById('chat-form');
const sendBtn = document.getElementById('send-btn');
form.addEventListener('submit', () => {
sendBtn.disabled = true;
sendBtn.textContent = 'Sending...';
});
</script>
</body>
</html>
