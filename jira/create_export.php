<?php

require 'vendor/autoload.php';

use League\HTMLToMarkdown\HtmlConverter;

/* CONFIG START */
$SECRET = 'NONE';  // IMPORTANT: Set this.
$AC_BASE_URL = 'https://your-domain.name/';  // needs trailing slash
/* CONFIG END */

if ($SECRET == 'NONE') {
    die('You fool! Please read the code and setup the configuration.');
}

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    ?>
    <form method="POST">
        <label>Secret: <input name="SECRET" type="password" /></label><br />
        <label>projectId: <input name="projectId" /></label><br />
        <label>projectKey: <input name="projectKey" /> (optional, but recommend)</label><br />
        <label>openStatus: <select name="openStatus"><option>Open</option><option>To Do</option></select></label><br />
        <input type="submit" value="Submit" />
    </form>
    <a href="CSV-configuration.txt">Download import configuration (right click -&gt; save as)</a>
    <?php
    die();
}

if ($_POST["SECRET"] != $SECRET) {
    header('HTTP/1.0 403 Forbidden');
    die('Access denied');
}

$projectId = isset($_POST["projectId"]) ? intval($_POST["projectId"]) : null;
if (is_null($projectId)) {
    header('HTTP/1.0 500 Error');
    die('No project id could be found');
}

require(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php');
$db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . '' . ';charset=utf8', DB_USER, DB_PASS);
$db->exec('SET names utf8');

$projectKey = isset($_POST["projectKey"]) ? $_POST["projectKey"] : null;
$openStatus = isset($_POST["openStatus"]) ? $_POST["openStatus"] : 'To Do';

$headers = [
    "Summary",
    "Issue key",
    "Issue id",
    "Parent id",
    "Issue Type",
    "Status",
    "Priority",
    "Resolution",
    "Assignee",
    "Reporter",
    "Creator",
    "Created",
    "Updated",
    "Epic Link",
    "Epic Name",
    "Resolved",
    "Due Date",
    "Description",
    "Original Estimate",
    "Remaining Estimate",
    "Time Spent",
];
$multipleHeaders = [
    "Watchers",
    "Outward issue link (Blocks)",
    "Comment",
    "Attachment",
    "Labels",
];

$baseRow = [];
foreach ($headers as $header) {
    $baseRow[$header] = null;
}
foreach ($multipleHeaders as $header) {
    $baseRow[$header] = [];
}
$headerRow = $headers;
$rows = [];
$userMap = [];
$issueMap = [];

function ensure_date_format($date) {
    if (!isset($date)) return '';
    $dateObj = date_parse($date);
    return sprintf("%04d-%02d-%02d %02d:%02d:%02d", $dateObj["year"], $dateObj["month"], $dateObj["day"], $dateObj["hour"], $dateObj["minute"], $dateObj["second"]);
}

// see https://jira.atlassian.com/secure/WikiRendererHelpAction.jspa?section=all
function ensure_text_format($html) {
    // Note: Markdown ist not the correct format either, but much easier to further convert ;-)
    $converter = new HtmlConverter();
    $text = $converter->convert($html);
    $text = str_replace('```', '{code}', $text);  // ```some code``` --> {code}some code{code}
    $text = preg_replace('#^> (.*?)$#m', '{quote}\\1{quote}', $text);  // will mark each line as quote individually
    $text = preg_replace_callback('#<span class="mention">(.*?)</span>#', function ($matches) {
        global $userMap;

        if (isset($userMap[$matches[1]]))
            return '[~' . $userMap[$matches[1]] . ']';
        else
            return $matches[1];
    }, $text);  // remove mentions
    $text = preg_replace('#\\[(.*?)\\]\\((.*?)\\)#', '[\\1|\\2]', $text);  // convert links to Jira syntax
    return $text;
}

$dbs = $db->prepare('SELECT * FROM users');
$dbs->execute();

while ($rowObj = $dbs->fetchObject()) {
    $userMap[$rowObj->first_name . ' ' . $rowObj->last_name] = $rowObj->email;
}

$dbs = $db->prepare('SELECT t.*, u.email AS assignee_email FROM tasks AS t LEFT OUTER JOIN users AS u ON (u.id = t.assignee_id) WHERE t.project_id = :project_id AND t.is_trashed = 0');
$dbs->execute(['project_id' => $projectId]);

$maxIssueNumber = 0;
while ($rowObj = $dbs->fetchObject())
{
    $row = $baseRow;
    $maxIssueNumber = max($maxIssueNumber, $rowObj->task_number);
    $row["Summary"] = $rowObj->name;
    $row["Description"] = ensure_text_format($rowObj->body);
    $row["Issue key"] = isset($projectKey) ? ($projectKey . '-' . $rowObj->task_number) : '';
    $row["Issue id"] = 'task:' . $rowObj->id;
    $row["Issue Type"] = 'Task';
    $row["Epic Link"] = 'tasklist:' . $rowObj->task_list_id;
    $row["Priority"] = $rowObj->is_important ? 'Highest': '';
    $row["Status"] = is_null($rowObj->completed_on) ? $openStatus : 'Done';
    $row["Resolution"] = is_null($rowObj->completed_on) ? '' : 'Done';
    $row["Due Date"] = ensure_date_format($rowObj->due_on);
    $row["Created"] = ensure_date_format($rowObj->created_on);
    $row["Reporter"] = $rowObj->created_by_email;
    $row["Creator"] = $row["Reporter"];
    $row["Updated"] = ensure_date_format($rowObj->updated_on);
    $row["Original Estimate"] = $rowObj->estimate > 0 ? $rowObj->estimate * 3600 : '';
    $row["Assignee"] = isset($rowObj->assignee_email) ? $rowObj->assignee_email : '';

    // Dependencies / Related
    $subDbs = $db->prepare('SELECT t.* FROM task_dependencies AS td LEFT OUTER JOIN tasks AS t ON (t.id = td.child_id) WHERE td.parent_id = :parent_id');
    $subDbs->execute(['parent_id' => $rowObj->id]);
    $row["Outward issue link (Blocks)"] = [];
    while ($subRowObj = $subDbs->fetchObject())
    {
        $row["Outward issue link (Blocks)"][] = 'task:' . $subRowObj->id;
    }

    // Watchers
    $subDbs = $db->prepare('SELECT * FROM subscriptions WHERE parent_type = "Task" AND parent_id = :parent_id');
    $subDbs->execute(['parent_id' => $rowObj->id]);
    $row["Watchers"] = [];
    while ($subRowObj = $subDbs->fetchObject())
    {
        $row["Watchers"][] = $subRowObj->user_email;
    }

    // Comments
    $subDbs = $db->prepare('SELECT * FROM comments WHERE parent_type = "Task" AND parent_id = :parent_id');
    $subDbs->execute(['parent_id' => $rowObj->id]);
    $row["Comment"] = [];
    while ($subRowObj = $subDbs->fetchObject())
    {
        $row["Comment"][] = ensure_date_format($subRowObj->created_on) . ';' .
                            $subRowObj->created_by_email . ';' .
                            ensure_text_format($subRowObj->body);
    }

    // Attachments
    $subDbs = $db->prepare('SELECT a.* FROM attachments AS a LEFT OUTER JOIN comments AS c ON (a.parent_type = "Comment" AND c.id = a.parent_id) WHERE (a.parent_type = "Task" AND a.parent_id = :parent_id) OR  (a.parent_type = "Comment" AND c.parent_type = "Task" AND c.parent_id = :parent_id)');
    $subDbs->execute(['parent_id' => $rowObj->id]);
    $row["Attachment"] = [];
    while ($subRowObj = $subDbs->fetchObject())
    {
        $row["Attachment"][] =
            ensure_date_format($subRowObj->created_on) . ';' .
            $subRowObj->created_by_email . ';' .
            $subRowObj->name . ';' .
            $AC_BASE_URL . 'proxy.php?module=system&proxy=download_file&context=attachments' .
                '&name=' . urlencode($subRowObj->location) .
                '&original_file_name=' . urlencode($subRowObj->name) .
                '&id=' . urlencode($subRowObj->id) .
                '&size=' . urlencode($subRowObj->size) .
                '&md5=' . urlencode($subRowObj->md5) .
                '&timestamp=' . urlencode($subRowObj->created_on);
    }

    // Labels
    $subDbs = $db->prepare('SELECT l.* FROM parents_labels AS pl LEFT OUTER JOIN labels AS l ON (l.id = pl.label_id) WHERE parent_type = "Task" AND pl.parent_id = :parent_id');
    $subDbs->execute(['parent_id' => $rowObj->id]);
    $row["Labels"] = [];
    while ($subRowObj = $subDbs->fetchObject())
    {
        $row["Labels"][] = $subRowObj->name;
    }

    $issueMap[$row['Issue id']] = $row['Issue key'];
    $rows[] = $row;
}

$dbs = $db->prepare('SELECT * FROM task_lists WHERE project_id = :project_id AND is_trashed = 0 ORDER BY position');
$dbs->execute(['project_id' => $projectId]);

while ($rowObj = $dbs->fetchObject())
{
    $row = $baseRow;
    $maxIssueNumber++;
    $row["Summary"] = $rowObj->name;
    $row["Epic Name"] = $rowObj->name;
    $row["Issue key"] = isset($projectKey) ? ($projectKey . '-' . $maxIssueNumber) : '';
    $row["Issue id"] = 'tasklist:' . $rowObj->id;
    $row["Issue Type"] = 'Epic';
    $row["Status"] = is_null($rowObj->completed_on) ? $openStatus : 'Done';
    $row["Resolution"] = is_null($rowObj->completed_on) ? '' : 'Done';
    $row["Due Date"] = $rowObj->due_on;
    $row["Created"] = ensure_date_format($rowObj->created_on);
    $row["Reporter"] = $rowObj->created_by_email;
    $row["Creator"] = $row["Reporter"];
    $row["Updated"] = $rowObj->updated_on;
    $issueMap[$row['Issue id']] = $row['Issue key'];
    $rows[] = $row;
}

$dbs = $db->prepare('SELECT st.*, u.email AS assignee_email FROM subtasks AS st LEFT OUTER JOIN users AS u ON (u.id = st.assignee_id) LEFT OUTER JOIN tasks AS t ON (st.task_id = t.id) WHERE t.project_id = :project_id AND st.is_trashed = 0');
$dbs->execute(['project_id' => $projectId]);

while ($rowObj = $dbs->fetchObject())
{
    $row = $baseRow;
    $maxIssueNumber++;
    $row["Summary"] = $rowObj->body;
    $row["Issue key"] = isset($projectKey) ? ($projectKey . '-' . $maxIssueNumber) : '';
    $row["Issue id"] = 'subtask:' . $rowObj->id;
    $row["Parent id"] = 'task:' . $rowObj->task_id;
    $row["Issue Type"] = 'Sub-task';
    $row["Status"] = is_null($rowObj->completed_on) ? $openStatus : 'Done';
    $row["Resolution"] = is_null($rowObj->completed_on) ? '' : 'Done';
    $row["Created"] = ensure_date_format($rowObj->created_on);
    $row["Reporter"] = $rowObj->created_by_email;
    $row["Creator"] = $row["Reporter"];
    $row["Updated"] = ensure_date_format($rowObj->updated_on);
    $row["Assignee"] = isset($rowObj->assignee_email) ? $rowObj->assignee_email : '';
    $issueMap[$row['Issue id']] = $row['Issue key'];
    $rows[] = $row;
}

foreach ($rows as &$row) {
    if (isset($row["Epic Link"]) && $row["Epic Link"] && isset($issueMap[$row["Epic Link"]]))
        $row["Epic Link"] = $issueMap[$row["Epic Link"]];

    if (isset($row["Outward issue link (Blocks)"]) && is_array($row["Outward issue link (Blocks)"])) {
        $blocks = [];
        foreach ($row["Outward issue link (Blocks)"] as $block) {
            if (isset($issueMap[$block]))
                $blocks[] = $issueMap[$block];
            else
                $blocks[] = $block;
        }
        $row["Outward issue link (Blocks)"] = $blocks;
    }
}

$multipleHeaderCount = [];
foreach ($multipleHeaders as $header) {
    $multipleHeaderCount[$header] = 0;
    foreach ($rows as $row)
    {
        if (count($row[$header]) > $multipleHeaderCount[$header])
            $multipleHeaderCount[$header] = count($row[$header]);
    }
}

foreach ($multipleHeaderCount as $header => $headerCount) {
    for ($i = 0; $i < $headerCount; $i++)
    {
        $headerRow[] = $header;
    }
}

$tfh = tmpfile();
fputcsv($tfh, $headerRow);

foreach ($rows as $row) {
    $outputRow = [];
    foreach ($headers as $header)
    {
        if (isset($row[$header]))
            $outputRow[] = $row[$header];
        else
            $outputRow[] = '';
    }
    foreach ($multipleHeaders as $header)
    {
        for ($i = 0; $i < $multipleHeaderCount[$header]; $i++)
        {
            if (isset($row[$header]) && isset($row[$header][$i]))
                $outputRow[] = $row[$header][$i];
            else
                $outputRow[] = '';
        }
    }
    fputcsv($tfh, $outputRow);
}

header('Content-Type: application/octet-stream');
header("Content-Transfer-Encoding: Binary");
header("Content-disposition: attachment; filename=\"export-ac-" . $projectId . ".csv\"");

fseek($tfh, 0);
while ($fileContent = fread($tfh, 1024))
    echo $fileContent;
fclose($tfh);

