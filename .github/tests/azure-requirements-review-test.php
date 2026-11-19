<?php

declare(strict_types=1);

require __DIR__.'/../scripts/azure-requirements-review.php';

function requirementsCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

requirementsCheck(requirementIds('PHP 8.4, release 3.5.1.3') === [], 'Ordinary versions parsed as requirements');
requirementsCheck(requirementIds('ТВ: 3.5.1.3, 3.7.1.3') === ['3.5.1.3', '3.7.1.3'], 'Ukrainian list not parsed');
requirementsCheck(requirementIds('Requirements: 3.5.1.3') === ['3.5.1.3'], 'English declaration not parsed');
requirementsCheck(requirementIds('Технічні вимоги: 3.5; 3.20') === ['3.5', '3.20'], 'Group declaration not parsed');
requirementsCheck(requirementIds('**ТВ:** 3.5.1.3.') === ['3.5.1.3'], 'Markdown or trailing punctuation lost requirement');
requirementsCheck(requirementPrIds(['title' => '#963: 3.20 medical devices', 'body' => ''], static fn (): array => []) === ['3.20'], 'Leading PR clause not detected');
requirementsCheck(requirementPrIds(['title' => 'Feature', 'body' => 'Closes #7'], static fn (): array =>
    ['title' => '3.7.1.3 Вимоги', 'body' => 'ТВ: 3.5.1.3']) === ['3.5.1.3', '3.7.1.3'], 'Linked issue not inspected');
$linkedCalls = 0;
$linkedIds = requirementPrIds(['number' => 964, 'title' => 'Feature', 'body' => ''], static function ($method, $url, $payload) use (&$linkedCalls): array {
    $linkedCalls++;
    if ($url === 'https://api.github.com/graphql') {
        requirementsCheck($payload['variables']['number'] === 964, 'Wrong PR linkage lookup');
        return ['data' => ['repository' => ['pullRequest' => ['closingIssuesReferences' => ['nodes' => [
            ['number' => 963, 'repository' => ['nameWithOwner' => REVIEW_REPOSITORY]],
            ['number' => 1, 'repository' => ['nameWithOwner' => 'untrusted/repository']],
        ], 'pageInfo' => ['hasNextPage' => false]]]]]];
    }
    requirementsCheck(str_ends_with($url, '/issues/963'), 'Foreign issue was fetched');
    return ['title' => '#963: 3.20 medical devices', 'body' => 'ТВ: 3.20.2'];
});
requirementsCheck($linkedIds === ['3.20'] && $linkedCalls === 2, 'Sidebar linkage or overlapping scopes not handled');
$corpus = requirementCorpus();
$clause = requirementClause($corpus, '3.5.1.3');
requirementsCheck($corpus['edition'] === '39' && $corpus['pdfPages'] === 218, 'Unexpected edition/pages');
requirementsCheck(str_contains($clause['text'], '3.5.1.3.1.1.5') && str_contains($clause['text'], 'skip_medication_dispense_sign'), 'Child requirements omitted');
requirementsCheck(!str_contains($clause['text'], '3.5.1.4 Вимоги'), 'Sibling requirements leaked');
$plan = requirementPlan($corpus, ['3.5']);
requirementsCheck(count($plan) > 1, 'Broad group was not divided');
$plannedIds = [];
foreach ($plan as $part) {
    $plannedIds = array_merge($plannedIds, $part['includedIds'], array_column($part['parents'], 'id'));
    requirementsCheck(in_array('3.5', array_column($part['parents'], 'id'), true), 'Shared group conditions were dropped');
    requirementsCheck(strlen($part['text']) <= 20_000, 'Group part exceeds target budget');
}
foreach ($corpus['sections'] as $section) {
    if ($section['id'] === '3.5' || str_starts_with($section['id'], '3.5.')) {
        requirementsCheck(in_array($section['id'], $plannedIds, true), 'Group requirement silently omitted: '.$section['id']);
    }
}
requirementsCheck(count(requirementPlan($corpus, ['3.5.1.3'])) === 1, 'Concrete clause was unnecessarily split');
try {
    requirementClause($corpus, '3.999.1');
    throw new LogicException('Invented requirement accepted');
} catch (RuntimeException $error) {
    requirementsCheck(str_contains($error->getMessage(), 'not found'), 'Wrong missing-clause failure');
}
foreach (['.env', 'app/../.env', 'vendor/Example.php', 'app/private.pem', 'docs/technical-requirements/edition-39.json'] as $path) {
    requirementsCheck(!requirementCodePath($path), 'Secret/non-source path accepted');
}
requirementsCheck(requirementCodePath('app/Livewire/Example.php'), 'Source path rejected');
$context = ['head' => ['app/Example.php' => ['lines' => [4 => 'return false;']]], 'base' => []];
$result = ['status' => 'gap', 'change' => 'unknown', 'explanation' => 'Конкретна невідповідність', 'nextStep' => 'Виправити перевірку та додати regression test.', 'evidence' => [['side' => 'head', 'path' => 'app/Example.php', 'line' => 4]]];
requirementsCheck(requirementResult($result, $context) === $result, 'Valid citation rejected');
foreach ([['status' => 'safe'], ['nextStep' => ''], ['change' => 'regressed'], ['evidence' => []], ['evidence' => [['side' => 'head', 'path' => 'app/Example.php', 'line' => 5]]]] as $change) {
    try {
        requirementResult(array_replace($result, $change), $context);
        throw new LogicException('Invalid assessment accepted');
    } catch (RuntimeException) {
    }
}
function simulateRequirements(array $options = []): array
{
    putenv('GITHUB_REPOSITORY='.REVIEW_REPOSITORY);
    putenv('GITHUB_REF=refs/heads/main');
    putenv('PR_NUMBER=123');
    putenv('GITHUB_RUN_ID=456');
    putenv('AZURE_OPENAI_ENDPOINT=https://test-resource.cognitiveservices.azure.com');
    putenv('AZURE_OPENAI_DEPLOYMENT=gpt-6.2-astra');
    putenv('EXPECTED_HEAD_SHA');
    putenv('AZURE_REVIEW_HEAD_SHA');
    putenv('AZURE_REVIEW_CHECK_ID=42');
    $pr = ['number' => 123, 'state' => 'open', 'draft' => false, 'title' => $options['title'] ?? 'Feature', 'body' => $options['body'] ?? 'ТВ: 3.5.1.3',
        'head' => ['sha' => str_repeat('a', 40)], 'base' => ['sha' => str_repeat('b', 40), 'ref' => 'main']];
    $models = 0;
    $reads = 0;
    $comments = [];
    $updates = [];
    $selections = [];
    $selectionData = [];
    $sourceUrls = [];
    $sourceReads = 0;
    $request = static function ($method, $url, $payload) use ($pr, $options, &$models, &$reads, &$comments, &$updates, &$selections, &$selectionData, &$sourceUrls, &$sourceReads): array {
        if ($url === 'https://api.github.com/graphql') {
            return ['data' => ['repository' => ['pullRequest' => ['closingIssuesReferences' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]]]]]];
        }
        if (str_contains($url, '/check-runs/')) {
            if ($method === 'GET') {
                return ['status' => $options['checkStatus'] ?? 'in_progress'];
            }
            $updates[] = $payload;
            return [];
        }
        if (str_ends_with($url, '/pulls/123')) {
            $reads++;
            if (!empty($options['staleRequirements']) && $models > 1) {
                return array_replace($pr, ['body' => 'ТВ: 3.20']);
            }
            return !empty($options['stale']) && $models > 1 ? array_replace_recursive($pr, ['head' => ['sha' => str_repeat('c', 40)]]) : $pr;
        }
        if (str_contains($url, '/comments?')) {
            return $options['comments'] ?? [];
        }
        if (str_contains($url, '/files?')) {
            return array_merge([['filename' => 'app/Example.php', 'status' => 'modified', 'patch' => "@@ -1 +1 @@\n-old\n+new"]], $options['extraFiles'] ?? []);
        }
        if (str_contains($url, '/git/trees/')) {
            return ['truncated' => false, 'tree' => [['type' => 'blob', 'path' => 'app/Example.php']]];
        }
        if (str_contains($url, '/contents/')) {
            $sourceReads++;
            $sourceUrls[] = $url;
            $text = "<?php\nreturn true;\n".(!empty($options['truncated']) ? str_repeat("// line\n", 500) : '');
            return ['type' => 'file', 'encoding' => 'base64', 'content' => base64_encode($text)];
        }
        if (str_contains($url, '/chat/completions')) {
            $models++;
            requirementsCheck($payload['model'] === getenv('AZURE_OPENAI_DEPLOYMENT'), 'Requirements review used the wrong Azure deployment');
            requirementsCheck($payload['store'] === false && $payload['max_completion_tokens'] === 6000, 'Missing bounded request');
            $isSelection = str_starts_with($payload['messages'][0]['content'], 'Select relevant');
            if ($isSelection) {
                $data = json_decode($payload['messages'][1]['content'], true, 512, JSON_THROW_ON_ERROR);
                $selections[] = $data['requirements'][0]['id'];
                $selectionData[] = $data;
            }
            $result = $isSelection ? ['paths' => $options['paths'] ?? ['app/Example.php']] :
                ['status' => 'covered', 'change' => 'unknown', 'nextStep' => '', 'explanation' => 'Поведінка підтверджена кодом',
                    'evidence' => [['side' => 'head', 'path' => 'app/Example.php', 'line' => $options['line'] ?? 2]]];
            if ($isSelection) {
                $result = $options['selectionResults'][count($selections) - 1] ?? $result;
                if (($options['invalidSelectionClause'] ?? null) === $data['requirements'][0]['id']) {
                    $result = ['paths' => ['app/Missing.php']];
                }
            }
            if (!$isSelection && isset($options['assessment'])) {
                $result = array_replace($result, $options['assessment']);
            }
            return ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($result)]]]];
        }
        if (in_array($method, ['POST', 'PATCH'], true) && str_contains($url, '/comments')) {
            $comments[] = $payload['body'];
            return ['id' => 1];
        }
        throw new RuntimeException('Unexpected request in requirements fixture');
    };
    $error = null;
    try {
        runRequirementsReview($request);
    } catch (Throwable $failure) {
        $error = $failure->getMessage();
    } finally {
        if (isset($options['finishStatus'])) {
            finishAzureReview($request, $options['finishStatus']);
        }
    }

    return compact('models', 'comments', 'updates', 'error', 'selections', 'selectionData', 'sourceUrls', 'sourceReads');
}
$simulation = simulateRequirements();
requirementsCheck($simulation['error'] === null && $simulation['models'] === 2 && count($simulation['comments']) === 1, 'Full requirements flow failed');
requirementsCheck(str_contains($simulation['comments'][0], 'PDF стор. 48–50') && str_contains($simulation['comments'][0], '/blob/'.str_repeat('a', 40)), 'Missing verified source pages/code links');
$repeat = simulateRequirements(['comments' => [['id' => 1, 'user' => ['login' => 'github-actions[bot]'], 'body' => $simulation['comments'][0]]]]);
requirementsCheck($repeat['models'] === 0 && $repeat['comments'] === [], 'Repeated review spent tokens');
$group = simulateRequirements(['body' => 'ТВ: 3.5, 3.5.1.3']);
requirementsCheck($group['error'] === null && $group['models'] === count($plan) * 2
    && $group['selections'] === array_column($plan, 'id'), 'Group did not receive independent complete context selection');
requirementsCheck(str_contains($group['comments'][0], 'Заявлено: ТВ 3.5.') && str_contains($group['comments'][0], 'Підтверджено: '.count($plan)), 'Group overview is missing');
requirementsCheck($group['sourceReads'] === 2, 'Repeated source context was not cached');
$gap = simulateRequirements(['assessment' => ['status' => 'gap', 'nextStep' => 'Додати перевірку та regression test.']]);
requirementsCheck($gap['error'] === null && str_contains($gap['comments'][0], '**Наступний крок:**') && str_contains($gap['comments'][0], 'невідповідностей: 1'), 'Actionable gap guidance missing');
$truncated = simulateRequirements(['truncated' => true]);
requirementsCheck($truncated['error'] === null && str_contains($truncated['comments'][0], 'потребують перевірки: 1'), 'Incomplete context incorrectly certified');
$staleRequirements = simulateRequirements(['staleRequirements' => true]);
requirementsCheck($staleRequirements['comments'] === [] && $staleRequirements['updates'][count($staleRequirements['updates']) - 1]['conclusion'] === 'neutral', 'Changed requirements result published');
$none = simulateRequirements(['body' => 'No requirements declared', 'finishStatus' => 'success']);
requirementsCheck($none['models'] === 0 && $none['comments'] === [] && count($none['updates']) === 1
    && $none['updates'][0]['conclusion'] === 'neutral', 'Successful cleanup overwrote the neutral no-requirements result');
$finished = simulateRequirements(['finishStatus' => 'success']);
requirementsCheck($finished['error'] === null && $finished['models'] === 2
    && end($finished['updates'])['conclusion'] === 'success', 'Successful cleanup overwrote the completed requirements review');
foreach ([['line' => 900], ['body' => 'ТВ: 3.999.1']] as $option) {
    $bad = simulateRequirements($option);
    requirementsCheck($bad['error'] !== null && $bad['comments'] === [], 'Unsafe or invalid result published');
}
$filtered = simulateRequirements(['extraFiles' => array_map(static fn (string $path): array =>
    ['filename' => $path, 'status' => 'removed', 'patch' => "@@ -1 +0,0 @@\n-old"],
    ['app/Deleted.php', 'resources/lang/uk/messages.php', 'resources/js/app.js', 'README.md'])]);
requirementsCheck($filtered['error'] === null
    && $filtered['selectionData'][0]['changedPaths'] === ['app/Example.php']
    && $filtered['selectionData'][0]['repositoryPaths'] === ['app/Example.php'], 'Selection advertised files outside the allowed head index');
foreach (['.env', 'app/Missing.php', 'app/../config/app.php', 'app\\Example.php', 'resources/lang/uk/messages.php'] as $path) {
    $recovered = simulateRequirements(['selectionResults' => [['paths' => ['app/Example.php', $path]]]]);
    requirementsCheck($recovered['error'] === null && $recovered['models'] === 3 && $recovered['sourceReads'] === 2
        && isset($recovered['selectionData'][1]['validationError'])
        && $recovered['updates'][count($recovered['updates']) - 1]['conclusion'] === 'success', 'Invalid path selection did not recover within one retry');
    foreach ($recovered['sourceUrls'] as $url) {
        requirementsCheck(str_contains($url, '/contents/app/Example.php?ref='), 'Unapproved source was fetched');
    }
}
foreach ([['paths' => ['app/Example.php', 'app/Example.php']], ['paths' => [7]], ['paths' => ['named' => 'app/Example.php']],
    ['paths' => 'app/Example.php'], ['paths' => array_fill(0, 7, 'app/Example.php')], []] as $invalid) {
    $recovered = simulateRequirements(['selectionResults' => [$invalid]]);
    requirementsCheck($recovered['error'] === null && $recovered['models'] === 3, 'Malformed selection was not retried');
}
$incomplete = simulateRequirements(['paths' => ['app/Example.php', '.env']]);
requirementsCheck($incomplete['error'] === null && $incomplete['models'] === 2 && $incomplete['sourceReads'] === 0
    && count($incomplete['comments']) === 1
    && str_contains($incomplete['comments'][0], '**Рев’ю неповне.**')
    && str_contains($incomplete['comments'][0], 'потребують перевірки: 1')
    && !str_contains($incomplete['comments'][0], '<!-- requirements-head:')
    && $incomplete['updates'][count($incomplete['updates']) - 1]['conclusion'] === 'neutral', 'Exhausted selection was fetched, hidden or certified as complete');
$retried = simulateRequirements(['comments' => [['id' => 1, 'user' => ['login' => 'github-actions[bot]'], 'body' => $incomplete['comments'][0]]]]);
requirementsCheck($retried['error'] === null && $retried['models'] === 2 && count($retried['comments']) === 1
    && str_contains($retried['comments'][0], '**Рев’ю завершено.**'), 'Incomplete report suppressed a new review of the same commit');
$failedClause = $plan[intdiv(count($plan), 2)]['id'];
$partialGroup = simulateRequirements(['body' => 'ТВ: 3.5', 'invalidSelectionClause' => $failedClause]);
requirementsCheck($partialGroup['error'] === null && $partialGroup['models'] === count($plan) * 2
    && array_values(array_unique($partialGroup['selections'])) === array_column($plan, 'id')
    && str_contains(implode('', $partialGroup['comments']), 'Підтверджено: '.(count($plan) - 1))
    && str_contains(implode('', $partialGroup['comments']), 'потребують перевірки: 1')
    && str_contains(implode('', $partialGroup['comments']), 'контексту: ТВ '.$failedClause.'.')
    && $partialGroup['updates'][count($partialGroup['updates']) - 1]['conclusion'] === 'neutral', 'One failed clause discarded previous results or stopped later clauses');
$stalePartial = simulateRequirements(['paths' => ['.env'], 'stale' => true]);
requirementsCheck($stalePartial['comments'] === [] && $stalePartial['updates'][count($stalePartial['updates']) - 1]['conclusion'] === 'neutral', 'Incomplete results were published for a stale head');
$stale = simulateRequirements(['stale' => true]);
requirementsCheck($stale['comments'] === [] && $stale['updates'][count($stale['updates']) - 1]['conclusion'] === 'neutral', 'Stale result published');
$published = [];
$reportRequest = static function ($method, $url, $payload) use (&$published): array {
    if ($method === 'GET') {
        return [];
    }
    $published[] = $payload['body'];
    return ['id' => count($published)];
};
$longEntries = array_map(static fn (int $i): string => 'PART'.$i.str_repeat('x', 30_000), range(1, 3));
$report = publishRequirementReport($reportRequest, 'https://api.github.com/repos/'.REVIEW_REPOSITORY, '123', null, 'Completed summary', $longEntries, str_repeat('a', 40));
requirementsCheck(count($published) === 4 && $published[3] === $report, 'Completion was not published last');
foreach ($published as $page) {
    requirementsCheck(strlen($page) < 60_000, 'Report exceeds comment limit');
}
foreach ($longEntries as $entry) {
    requirementsCheck(str_contains(implode('', $published), $entry), 'Long report omitted results');
}
echo 'Requirements parsing, corpus, code citations, full flow, deduplication and stale-result checks passed ('.count($corpus['sections'])." clauses).\n";
