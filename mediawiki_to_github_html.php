<?php
date_default_timezone_set('UTC');  // Set timezone to UTC

$githubRepoPath = "Reqrefusion/FreeCAD-Documentation-html";  // GitHub repository path

$githubToken = "ghp_XXX"; // Place your GitHub API token here.

$invalidPrefixes = array('Translations:', 'Draft:', 'Help:'); // Add more invalid terms if needed.

$languageCodes = array(
    'bg', 'cs', 'de', 'en', 'es', 'fi', 'fr', 'hr', 'hu', 'id', 'it', 'ja',
    'ko', 'pl', 'pt', 'pt-br', 'ro', 'ru', 'sk', 'sv', 'tr', 'uk', 'vi', 'zh', 'zh-cn', 'zh-tw', 'zh-hant'
);

// Function to get the file content from GitHub
function getGithubFileContent($filePath) {
    global $githubRepoPath, $githubToken;

    // Base URL for API
    $baseApiUrl = "https://api.github.com/repos/$githubRepoPath";

    // Set up common headers for cURL
    $headers = array(
        'Authorization: token ' . $githubToken,
        'User-Agent: PHP'
    );

    // Fetch file content
    $contentUrl = "$baseApiUrl/contents/" . urlencode($filePath);
    $ch = curl_init($contentUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $contentResponse = curl_exec($ch);
    curl_close($ch);

    if (!$contentResponse) {
        return null;
    }

    $contentData = json_decode($contentResponse, true);
    if (!isset($contentData['content'], $contentData['sha'])) {
        return null;
    }

    $decodedContent = base64_decode($contentData['content']);
    $sha = $contentData['sha'];

    // Fetch last modification date via commits API
    $commitsUrl = "$baseApiUrl/commits?path=" . urlencode($filePath);
    $ch = curl_init($commitsUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $commitsResponse = curl_exec($ch);
    curl_close($ch);

    $lastModified = null;
    if ($commitsResponse) {
        $commitsData = json_decode($commitsResponse, true);
        if (isset($commitsData[0]['sha'])) {
            $lastCommitSha = $commitsData[0]['sha'];
        }
        if (isset($commitsData[0]['commit']['committer']['date'])) {
            $lastModified = new DateTime($commitsData[0]['commit']['committer']['date']);
        }
    }
    // Return combined data
    return array(
        'content' => $decodedContent,
        'sha' => $sha,
        'lastModified' => $lastModified,
        'lastCommitSha' => $lastCommitSha
    );
}


// Function to update or create a file on GitHub
function updateGithubFile($filePath, $newContent, $sha, $pageTitle, $user, $comment) {
    global $githubRepoPath, $githubToken;
    $githubApiUrl = "https://api.github.com/repos/$githubRepoPath/contents/" . $filePath;

    $commitMessage = $comment ?: "Updated page $pageTitle";  // Default commit message if no comment

    // FreeCAD Wiki message board URL for the user
    $wikiUserUrl = "https://wiki.freecad.org/User:" . urlencode($user);

    // Author information for the commit
    $authorData = array(
        "name" => $user,  // FreeCAD Wiki username
        "email" => $wikiUserUrl  // Message board URL instead of email
    );

    // Data to send to GitHub API
    $data = array(
        "message" => $commitMessage,
        "content" => base64_encode($newContent),  // Encode the content in Base64
        "sha" => $sha,  // SHA of the previous file
        "author" => $authorData  // Author information
    );

    // Send the request via cURL
    $ch = curl_init($githubApiUrl);

    $headers = array(
        'Authorization: token ' . $githubToken,
        'User-Agent: PHP',
        'Content-Type: application/json'
    );

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $responseData = json_decode($response, true);

    curl_close($ch);

    if ($httpcode == 200 || $httpcode == 201) {
        echo "SUCCESS: '$filePath' successfully updated on GitHub by $user.<br>";
        return true;
    } else {
        echo "ERROR: Failed to update '$filePath' on GitHub. HTTP Code: $httpcode. Message: " . $responseData['message'] . "<br>";
        return false;
    }
}

// Function to download the image file from the Wiki and return binary data
function downloadWikiFile($fileTitle) {
    $fileUrl = "https://wiki.freecad.org/Special:FilePath/" . str_replace(' ', '_',$fileTitle);  // Direct URL to file
    echo "INFO: Trying to download file from: $fileUrl<br>";  // Debugging output

    $ch = curl_init($fileUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);  // Follow redirects

    $fileContent = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($httpcode == 200) {
        return $fileContent;
    } else {
        echo "ERROR: Could not download file '$fileTitle'. HTTP Code: $httpcode<br>";
        return null;
    }
}

// Function to create a valid file path based on the page title
function createFilePath($pageTitle, $type = "html") {
    global $languageCodes;
    if(strpos($pageTitle, 'File:') === 0 || strpos($pageTitle, 'Images:') === 0){
        return "wiki/File/" . sanitize_name($pageTitle);
    }


    $parts = explode('/', $pageTitle);
    $lastPart = end($parts);

    // Check if the last part is a language code
    $languageCode = null;
    foreach ($languageCodes as $code) {
        if ($lastPart === $code) {
            $languageCode = $code;
            break;
        }
    }

    // Create content parts
    $contentParts = $languageCode ? array_slice($parts, 0, -1) : $parts;

    // Sanitize the content and build the file path
    $sanitizedContent = array_map('sanitize_name', $contentParts);
    $fileName = implode('/', $sanitizedContent); // Without language code
    if ($type == "html") {
        $fileName .= ".html"; 
    }

    // Prepend language code if present
    if ($languageCode) {
        return "wiki/" . $languageCode . "/" . $fileName;
    }

    return "wiki/" . $fileName; // If no language code
}

// Function to sanitize file names
function sanitize_name($name) {
    // First, remove the "File:" or "Images:" prefix using str_replace
    $name = str_replace(array('File:', 'Images:'), '', $name);
    
    // Replace invalid characters (spaces and colons)
    return str_replace(array(' ', ':'), array('_', ';'), $name);  // Replace invalid characters
}

function adjust_links($htmlContent, $pageTitle) {
    if (!is_string($pageTitle)) {
        $pageTitle = ''; 
    }

    $numSlashesArray = explode('/', $pageTitle);
    $numSlashesArray = array_filter($numSlashesArray, function($value) {
        return trim($value) !== '';
    });
    $numSlashes = count($numSlashesArray)-1;

    if ($numSlashes < 0) {
        $numSlashes = 0;
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // Suppress warnings for invalid HTML
    $dom->loadHTML('<?xml encoding="UTF-8">' . $htmlContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Update all <a> tags' href attributes
    foreach ($xpath->query('//a[@href]') as $aTag) {
        $href = $aTag->getAttribute('href');

        if (strpos($href, '/File:') === 0) {
            $fileName = substr($href, strlen('/File:'));
            $aTag->setAttribute('href', str_repeat('../', $numSlashes) . "File/" . $fileName);
        } elseif (strpos($href, '/wiki/') === 0) {
            $relativeLink = substr($href, strlen('/wiki/'));
            $filePath = createFilePath($relativeLink).".html";
            $aTag->setAttribute('href', $filePath);
        } elseif (strpos($href, '/') === 0) {
            $parts = explode('#', $href);
            $newHref = get_last_segment(createFilePath($parts[0]), '\\');
            if (count($parts) > 1) {
                $newHref .= '#' . end($parts);
            }
            $aTag->setAttribute('href', $newHref);
        } else {
            //echo "Skipped href: $href<br>";
        }
    }

    // Update all <img> tags' src attributes
    foreach ($xpath->query('//img[@src]') as $imgTag) {
        $src = $imgTag->getAttribute('src');

        if (strpos($src, '/') === 0) {
            $fileName = basename($src);
            $fileName = preg_replace('/^\d+px-/', '', $fileName);
            $imgTag->setAttribute('src', str_repeat('../', $numSlashes) . "File/" . $fileName);
        } else {
            echo "Warning: Unexpected image src format: $src<br>";
        }

        // Remove srcset attribute if present
        if ($imgTag->hasAttribute('srcset')) {
            $imgTag->removeAttribute('srcset');
        }
    }

    // Add CSS and JS files
    $cssLink = '<link href="' . str_repeat('../', $numSlashes) . 'site.css" rel="stylesheet" type="text/css"/>';
    $jsScript = '<script src="' . str_repeat('../', $numSlashes) . 'site.js"></script>';
    $beforeHtmlContent = '<div class="mw-page-container">';
    $afterHtmlContent = '</div>';
    $bodyHeader = '<h1 id="firstHeading" class="firstHeading mw-first-heading"><span class="mw-page-title-main">' . htmlspecialchars($pageTitle) . '</span></h1>';

    $updatedHtml = $cssLink . $beforeHtmlContent . $bodyHeader . $dom->saveHTML() . $afterHtmlContent . $jsScript;

    return $updatedHtml;
}



// Helper function to get the last segment of a path
function get_last_segment($path, $separator) {
    $parts = explode($separator, $path);
    return end($parts);
}


// Check for recent changes made in the last 20 minutes
$currentTime = new DateTime(); // Get the current time
$timeLimit = clone $currentTime;
$timeLimit->modify('-35 minutes'); // Go back 35 minutes

// Format the timestamp
$timestampStart = $timeLimit->format('Y-m-d\TH:i:s\Z'); // 20 minutes ago timestamp

$wikiRecentChangesUrl = "https://wiki.freecad.org/api.php?action=query&list=recentchanges&rcprop=title|timestamp|user|comment&rclimit=500&rcend=" . $timestampStart . "&format=json";
echo $wikiRecentChangesUrl;

// Fetch the recent changes from the Wiki API
$recentChanges = file_get_contents($wikiRecentChangesUrl);
$recentChanges = json_decode($recentChanges, true);

// If there are no errors, process the changes
if (isset($recentChanges['query']['recentchanges'])) {
    $recentPages = array_reverse($recentChanges['query']['recentchanges']);  // Sort changes from oldest to newest

    foreach ($recentPages as $page) {
        $pageTitle = $page['title'];
        $user = $page['user'];  // User who made the change
        $comment = $page['comment'];  // Comment on the change
        $timestamp = new DateTime($page['timestamp']);  // Time of the change
        $filePath = createFilePath($pageTitle);
        $existingFileData = getGithubFileContent($filePath);


        // Check if the page title starts with an invalid prefix
        foreach ($invalidPrefixes as $prefix) {
            if (strpos($pageTitle, $prefix) === 0) {
                echo "INFO: '$pageTitle' starts with an invalid prefix '$prefix', skipping.<br>";
                continue 2; // Skip to the next page if invalid
            }
        }
		
        // Compare modification times to decide if the file needs to be updated
        if (isset($existingFileData) && $existingFileData['lastModified'] >= $timestamp) {
            echo "INFO: '$filePath' is already up-to-date.<br>";
            continue;  // If the GitHub file is up-to-date, skip
        }
        if (strpos($pageTitle, 'File:') === 0 || strpos($pageTitle, 'Images:') === 0) {
            echo "Processing file or image: $pageTitle (Modified by: $user, Comment: '$comment')<br>";

            // Download the file from the Wiki
            $fileContent = downloadWikiFile($pageTitle);
            if ($fileContent !== null) {
                // Update the file if it exists, or create a new one
                if (isset($existingFileData)) {
                    echo "INFO: Updating existing file '$filePath' on GitHub...<br>";
                    updateGithubFile($filePath, $fileContent, $existingFileData['sha'], $pageTitle, $user, $comment);
                } else {
                    echo "INFO: Creating new file '$filePath' on GitHub...<br>";
                    updateGithubFile($filePath, $fileContent, null, $pageTitle, $user, $comment);
                }
            }
        } else {
        echo "Processing page: $pageTitle (Modified by: $user, Comment: '$comment')<br>";

        // Fetch the Wiki page content
        $wikiPageUrl = "https://wiki.freecad.org/api.php?action=parse&page=" . urlencode($pageTitle) . "&prop=text&disableeditsection=true&format=json";
        $wikiContent = file_get_contents($wikiPageUrl);
        $wikiContent = json_decode($wikiContent, true);
        $wikihtml = $wikiContent['parse']['text']['*'];
        $updatedwikihtml = adjust_links($wikihtml, $pageTitle);

        /*
        // Skip if the content is already up-to-date
        if ($existingContent['content'] !== null && trim($wikiText) === trim($existingContent['content'])) {
            echo "INFO: '$filePath' is already up-to-date.<br>";
            continue; // If content is the same, skip
        }
*/
        // Update the file if it exists, or create a new one
        if ($existingFileData['content'] !== null) {
            echo "INFO: Updating existing file '$filePath' on GitHub...<br>";
            updateGithubFile($filePath, $updatedwikihtml, $existingFileData['sha'], $pageTitle, $user, $comment);
        } else {
            echo "INFO: Creating new file '$filePath' on GitHub...<br>";
            updateGithubFile($filePath, $updatedwikihtml, null, $pageTitle, $user, $comment);
        }
		}
    }
} else {
    echo "ERROR: Could not fetch recent changes from the wiki.<br>";
}

echo "All changes processed.<br>";

?>
