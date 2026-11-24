<?php

declare(strict_types=1);

require __DIR__.'/../scripts/azure-openai-review.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function simulateReview(array $options = []): array
{
    putenv('GITHUB_REPOSITORY='.($options['repository'] ?? 'openhealths/nationHealth'));
    putenv('GITHUB_REF='.($options['ref'] ?? 'refs/heads/main'));
    putenv('PR_NUMBER='.($options['number'] ?? '123'));
    putenv('AZURE_OPENAI_ENDPOINT=https://test-resource.cognitiveservices.azure.com/');
    putenv('AZURE_OPENAI_DEPLOYMENT=gpt-6.2-astra');
    putenv('EXPECTED_HEAD_SHA='.($options['expectedSha'] ?? ''));
    putenv('GITHUB_STEP_SUMMARY');
    putenv('GITHUB_RUN_ID=456');
    putenv('AZURE_REVIEW_CHECK_ID='.(!empty($options['checks']) ? '42' : ''));
    putenv('AZURE_REVIEW_HEAD_SHA='.($options['registeredSha'] ?? ''));
    $headSha = str_repeat('a', 40);
    $baseSha = str_repeat('b', 40);
    $pr = ['state' => 'open', 'draft' => false, 'changed_files' => 1,
        'head' => ['sha' => $headSha], 'base' => ['sha' => $baseSha, 'ref' => 'main']];
    $pr = array_replace_recursive($pr, $options['pr'] ?? []);
    $files = $options['files'] ?? [['filename' => 'app/Example.php', 'status' => 'modified',
        'patch' => "@@ -10,2 +10,3 @@\n existing\n+new bug\n existing"]];
    $finding = ['priority' => 'P2', 'path' => 'app/Example.php', 'line' => 11,
        'title' => 'Перевірити умову', 'explanation' => 'За конкретної умови повертається неправильний результат.'];
    $modelCalls = 0;
    $prReads = 0;
    $published = [];
    $checkUpdates = [];
    $failure = null;
    $request = static function (string $method, string $url, ?array $payload) use (
        &$modelCalls, &$prReads, &$published, &$checkUpdates, $pr, $options, $files, $finding
    ): array {
        if (str_contains($url, '/check-runs')) {
            if ($method === 'GET') {
                return ['status' => $options['checkStatus'] ?? 'in_progress'];
            }
            $checkUpdates[] = ['method' => $method, 'payload' => $payload,
                'modelCalls' => $modelCalls, 'publishedComments' => count($published)];

            return ['id' => 42];
        }
        if (str_contains($url, '/chat/completions')) {
            $modelCalls++;
            if (!empty($options['modelFailure'])) {
                throw new RuntimeException('Synthetic model failure');
            }
            check($payload['model'] === getenv('AZURE_OPENAI_DEPLOYMENT'), 'Code review used the wrong Azure deployment');
            check($payload['store'] === false && $payload['max_completion_tokens'] === 6000, 'Cost/storage settings are missing');

            return ['choices' => [['finish_reason' => 'stop', 'message' => [
                'content' => json_encode(['findings' => $options['findings'] ?? [$finding]], JSON_THROW_ON_ERROR),
            ]]], 'usage' => ['total_tokens' => 500]];
        }
        if (str_contains($url, '/comments?')) {
            return $options['comments'] ?? [];
        }
        if (str_contains($url, '/files?')) {
            return $files;
        }
        if ($method === 'GET' && str_ends_with($url, '/pulls/123')) {
            $prReads++;

            return $prReads > 1 && $modelCalls > 0 ? array_replace_recursive($pr, $options['currentPr'] ?? []) : $pr;
        }
        if (in_array($method, ['POST', 'PATCH'], true) && str_contains($url, '/comments')) {
            if (!empty($options['publishFailure'])) {
                throw new RuntimeException('Synthetic comment publication failure');
            }
            $published[] = ['method' => $method, 'url' => $url, 'body' => $payload['body']];

            return ['id' => 1];
        }
        throw new RuntimeException('Unexpected request in the fixture');
    };
    ob_start();
    try {
        if (!empty($options['start'])) {
            $outputs = startAzureReview($request);
            if ($outputs['active'] === 'true') {
                putenv('AZURE_REVIEW_CHECK_ID='.$outputs['check_id']);
                putenv('AZURE_REVIEW_HEAD_SHA='.$outputs['head_sha']);
            } else {
                return compact('published', 'modelCalls', 'failure', 'checkUpdates');
            }
        }
        runAzureReview($request);
    } catch (Throwable $error) {
        $failure = $error->getMessage();
    } finally {
        if (isset($options['finishStatus'])) {
            finishAzureReview($request, $options['finishStatus']);
        }
        ob_end_clean();
    }

    return compact('published', 'modelCalls', 'failure', 'checkUpdates');
}

$result = simulateReview();
check($result['failure'] === null && count($result['published']) === 1, 'Valid finding was not published');
check(str_contains($result['published'][0]['body'], '#L11'), 'Finding link must cite the reviewed line');

$result = simulateReview(['findings' => [['priority' => 'P2', 'path' => 'app/Other.php', 'line' => 11,
    'title' => 'Invented path', 'explanation' => 'Invented evidence']]]);
check($result['failure'] !== null && $result['published'] === [], 'Hallucinated paths must not be published');

$result = simulateReview(['findings' => [['priority' => 'P2', 'path' => 'app/Example.php', 'line' => 999,
    'title' => 'Wrong line', 'explanation' => 'Outside diff']]]);
check($result['failure'] !== null && $result['published'] === [], 'Lines outside the patch must not be published');

$result = simulateReview(['currentPr' => ['head' => ['sha' => str_repeat('c', 40)]]]);
check($result['modelCalls'] === 1 && $result['published'] === [], 'A changed head must discard stale findings');

$result = simulateReview(['currentPr' => ['draft' => true]]);
check($result['published'] === [], 'A PR converted to draft during review must not receive a comment');

$result = simulateReview(['expectedSha' => str_repeat('c', 40)]);
check($result['modelCalls'] === 0 && $result['published'] === [], 'An outdated queued event must not spend model tokens');

$fingerprint = '<!-- reviewed-head:'.str_repeat('a', 40).' base:'.str_repeat('b', 40).' -->';
$result = simulateReview(['comments' => [['id' => 42, 'user' => ['login' => 'github-actions[bot]'],
    'body' => REVIEW_MARKER."\n".$fingerprint]]]);
check($result['modelCalls'] === 0 && $result['published'] === [], 'Duplicate events must not repeat the model request');

$result = simulateReview(['comments' => [['id' => 42, 'user' => ['login' => 'untrusted-user'],
    'body' => REVIEW_MARKER."\n".$fingerprint]]]);
check($result['modelCalls'] === 1 && $result['published'][0]['method'] === 'POST', 'A spoofed marker must not edit or suppress user comments');

$result = simulateReview(['comments' => [['id' => 42, 'user' => ['login' => 'github-actions[bot]'], 'body' => REVIEW_MARKER]]]);
check($result['published'][0]['method'] === 'PATCH', 'A later head should update the bot comment');

$result = simulateReview(['files' => [['filename' => '.env', 'status' => 'modified', 'patch' => '+SECRET=example']]]);
check($result['modelCalls'] === 0 && str_contains($result['published'][0]['body'], 'Немає доступних'), 'Secret files must not be sent to the model');

$keyPaths = ['id_rsa', '.ssh/id_ed25519', 'keys/id_dsa', 'keys/id_ecdsa', 'keys/id_ed25519_sk',
    'keys/id_rsa.backup', 'private.pem', 'private.KEY', 'client.ppk', 'client.p12', 'client.PFX', 'client.jks', 'client.keystore'];
foreach ($keyPaths as $keyPath) {
    $result = simulateReview(['files' => [['filename' => $keyPath, 'status' => 'added', 'patch' => '+synthetic key fixture']]]);
    check($result['failure'] === null && $result['modelCalls'] === 0, 'Private-key path reached the model: '.$keyPath);
}

$result = simulateReview(['files' => [['filename' => 'keys/renamed.txt', 'previous_filename' => '.ssh/id_rsa',
    'status' => 'renamed', 'patch' => '+synthetic renamed fixture']]]);
check($result['modelCalls'] === 0, 'Renaming a private-key file must not bypass its exclusion');

foreach (['-----BEGIN PRIVATE KEY-----', '-----BEGIN RSA PRIVATE KEY-----', '-----BEGIN ENCRYPTED PRIVATE KEY-----',
    'PuTTY-User-Key-File-3: ssh-ed25519'] as $keyHeader) {
    $result = simulateReview(['files' => [['filename' => 'keys/custom-name.txt', 'status' => 'added', 'patch' => '+'.$keyHeader]]]);
    check($result['modelCalls'] === 0, 'Recognizable private-key material reached the model');
}
$allowedFiles = reviewDiffs([
    ['filename' => '.env.example', 'status' => 'added', 'patch' => '+EXAMPLE=value'],
    ['filename' => '.ssh/id_ed25519.pub', 'status' => 'added', 'patch' => '+synthetic public key'],
    ['filename' => 'app/KeyService.php', 'status' => 'added', 'patch' => '+application logic'],
]);
check(count($allowedFiles) === 3, 'Key filtering must preserve public keys, env examples and ordinary application code');

$files = [];
for ($index = 0; $index < 25; $index++) {
    $files[] = ['filename' => "app/File$index.php", 'status' => 'added', 'patch' => "@@ -0,0 +1 @@\n+example"];
}
check(count(reviewDiffs($files)) === 25, 'Review must not silently omit files after the twentieth');
check(reviewBatches(reviewDiffs([['filename' => 'app/Huge.php', 'status' => 'added', 'patch' => str_repeat('x', 60001)]]))['skippedLines'] === 1, 'An indivisible fragment must be reported');
check(!str_contains(reviewText('@team [link](https://example.com)', 200), '@team'), 'Model output must not notify mentioned users');

$result = simulateReview(['repository' => 'attacker/fork']);
check($result['failure'] !== null && $result['modelCalls'] === 0, 'Another repository must not call the model');
$result = simulateReview(['ref' => 'refs/heads/untrusted']);
check($result['failure'] !== null && $result['modelCalls'] === 0, 'Another ref must not call the model');
$result = simulateReview(['number' => '123/../456']);
check($result['failure'] !== null && $result['modelCalls'] === 0, 'PR input must not change the API path');

$result = simulateReview(['files' => $files, 'findings' => [], 'pr' => ['changed_files' => 25]]);
check($result['failure'] === null && str_contains($result['published'][0]['body'], '25 із 25'), 'All 25 eligible files must be reviewed and reported');

$prioritized = reviewDiffs([
    ['filename' => 'resources/view.php', 'status' => 'modified', 'patch' => '+view'],
    ['filename' => 'routes/web.php', 'status' => 'modified', 'patch' => '+route'],
    ['filename' => 'app/Action.php', 'status' => 'modified', 'patch' => '+action'],
    ['filename' => 'database/change.php', 'status' => 'modified', 'patch' => '+schema'],
]);
check(array_keys($prioritized) === ['app/Action.php', 'database/change.php', 'routes/web.php', 'resources/view.php'], 'Backend logic must precede frontend paths');

$largeLines = [];
for ($index = 1; $index <= 2000; $index++) {
    $largeLines[] = '+'.str_repeat('a', 40).$index;
}
$largePatch = "@@ -0,0 +1,2000 @@\n".implode("\n", $largeLines);
$parts = reviewPatchParts($largePatch);
check(count($parts['patches']) > 1 && $parts['skippedLines'] === 0, 'Large valid hunks must be split rather than discarded');
$visibleLines = [];
foreach ($parts['patches'] as $part) {
    check(strlen($part) <= 60000, 'A split part exceeded the request size');
    $visibleLines += reviewLines($part);
}
check(count($visibleLines) === 2000 && isset($visibleLines[1], $visibleLines[2000]), 'Splitting must preserve every new-side line number');
$result = simulateReview(['files' => [['filename' => 'app/Large.php', 'status' => 'added', 'patch' => $largePatch]], 'findings' => []]);
check($result['failure'] === null && $result['modelCalls'] > 1 && count($result['published']) === 1, 'Multiple model calls must produce one final comment');
$result = simulateReview(['files' => [['filename' => 'app/Large.php', 'status' => 'added', 'patch' => $largePatch]],
    'findings' => [], 'currentPr' => ['head' => ['sha' => str_repeat('c', 40)]]]);
check($result['modelCalls'] === 1 && $result['published'] === [], 'A head change must stop the remaining batches without publishing partial results');

$result = simulateReview(['start' => true, 'checkStatus' => 'completed', 'finishStatus' => 'success']);
check($result['checkUpdates'][0]['payload']['head_sha'] === str_repeat('a', 40)
    && $result['checkUpdates'][0]['modelCalls'] === 0
    && str_ends_with($result['checkUpdates'][0]['payload']['details_url'], '/actions/runs/456'),
    'The check must be registered on the PR commit before inference, with a run link');
$lastCheck = end($result['checkUpdates']);
check($lastCheck['payload']['conclusion'] === 'success' && $lastCheck['publishedComments'] === 1,
    'A successful check must follow final comment publication');
check(str_contains($result['published'][0]['body'], '**✅ Рев’ю завершено.**')
    && str_contains($result['published'][0]['body'], '/actions/runs/456'), 'The final comment needs an explicit completion status and run link');

foreach ([['findings' => [], 'expected' => 'success'], ['files' => [], 'expected' => 'neutral']] as $case) {
    $result = simulateReview(['checks' => true, 'checkStatus' => 'in_progress', 'finishStatus' => 'success', ...$case]);
    check(end($result['checkUpdates'])['payload']['conclusion'] === $case['expected'],
        'Successful cleanup must preserve the published result when a check read is stale');
}

$result = simulateReview(['start' => true, 'pr' => ['draft' => true]]);
check($result['checkUpdates'] === [] && $result['modelCalls'] === 0, 'Drafts must not register or execute a review');

$result = simulateReview(['checks' => true, 'files' => [['filename' => 'app/Large.php', 'status' => 'added', 'patch' => $largePatch]], 'findings' => []]);
$progress = array_values(array_filter($result['checkUpdates'], static fn (array $update): bool => $update['payload']['status'] === 'in_progress'));
check(count($progress) === 2 && str_contains($progress[0]['payload']['output']['title'], '1 із 2')
    && str_contains($progress[1]['payload']['output']['title'], '2 із 2'), 'Check progress must track both batches');

$result = simulateReview(['checks' => true, 'registeredSha' => str_repeat('c', 40)]);
check($result['modelCalls'] === 0 && end($result['checkUpdates'])['payload']['conclusion'] === 'neutral',
    'A head change after registration must not review a different commit under the old check');

$result = simulateReview(['checks' => true, 'currentPr' => ['head' => ['sha' => str_repeat('c', 40)]]]);
check($result['published'] === [] && end($result['checkUpdates'])['payload']['conclusion'] === 'neutral',
    'Stale results must finish neutrally without publishing');

$result = simulateReview(['checks' => true, 'comments' => [['id' => 42, 'user' => ['login' => 'github-actions[bot]'],
    'body' => REVIEW_MARKER."\n".$fingerprint]]]);
check($result['modelCalls'] === 0 && end($result['checkUpdates'])['payload']['conclusion'] === 'success',
    'A previously completed review must complete its new check without spending model tokens');

foreach (['modelFailure', 'publishFailure'] as $failureCase) {
    $result = simulateReview(['checks' => true, $failureCase => true, 'finishStatus' => 'failure']);
    check($result['failure'] !== null && end($result['checkUpdates'])['payload']['conclusion'] === 'failure',
        'A failed model request or comment publication must not finish green');
}

$result = simulateReview(['checks' => true, 'modelFailure' => true, 'finishStatus' => 'cancelled']);
check(end($result['checkUpdates'])['payload']['conclusion'] === 'cancelled', 'Cancellation must close an unfinished check');

$result = simulateReview(['checks' => true, 'files' => [], 'findings' => []]);
check($result['modelCalls'] === 0 && end($result['checkUpdates'])['payload']['conclusion'] === 'neutral',
    'No analyzable files must finish neutrally rather than claim successful analysis');

echo "36 review safety and behavior checks passed.\n";
