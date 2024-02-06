<?php
header('Content-Type: application/json');

$targetDir = "uploads/"; // directory to upload
if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
    die(json_encode(['error' => 'Failed to create upload directory.']));
}

function sanitizeFileName($fileName) { // SECURITY 
    $fileName = basename($fileName);
    return preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
}

if (!isset($_FILES['file']['error']) || is_array($_FILES['file']['error'])) { // Check for errors with file
    die(json_encode(['error' => 'Invalid parameters.']));
}

if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    die(json_encode(['error' => 'File upload error: ' . $_FILES['file']['error']]));
}

if (!isset($_POST['fileName']) || empty($_POST['fileName'])) { // Check for problems with file name
    die(json_encode(['error' => 'File name not provided.']));
}
$fileName = sanitizeFileName($_POST['fileName']);

if (!isset($_POST['chunkNumber']) || !is_numeric($_POST['chunkNumber'])) { // verify chunk
    die(json_encode(['error' => 'Invalid or missing chunk number.']));
}
$chunkNumber = (int)$_POST['chunkNumber'];

if (!isset($_POST['checksum'])) { // verify chunk
    die(json_encode(['error' => 'Checksum not provided.']));
}
$checksum = $_POST['checksum'];

$filePath = $targetDir . $fileName . '.part' . $chunkNumber; // make file path

if (!move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
    die(json_encode(['error' => 'Failed to move uploaded file.']));
}

$calculatedChecksum = hash_file('sha256', $filePath); // verify checksum 
if ($checksum !== $calculatedChecksum) {
    unlink($filePath);
    die(json_encode(['error' => 'Checksum verification failed.']));
}

$totalChunks = isset($_POST['totalChunks']) ? (int)$_POST['totalChunks'] : null;
if ($totalChunks === null) { // another check fo total chunks size 
    die(json_encode(['error' => 'Total chunks count not provided.']));
}

$allChunksUploaded = true;
for ($i = 1; $i <= $totalChunks; $i++) { // Making sure making all parts 
    if (!file_exists($targetDir . $fileName . '.part' . $i)) {
        $allChunksUploaded = false;
        break;
    }
}

if ($allChunksUploaded) {
    $finalFilePath = $targetDir . $fileName;
    if (!$out = @fopen($finalFilePath, "wb")) { // Check if can open final image for starting analysis 
        die(json_encode(['error' => 'Failed to open final file for writing.']));
    }

    for ($i = 1; $i <= $totalChunks; $i++) { // try to assembly parts
        $partFilePath = $targetDir . $fileName . '.part' . $i;
        if (!$in = @fopen($partFilePath, "rb")) {
            die(json_encode(['error' => 'Failed to open chunk ' . $i . ' for reading.']));
        }
        while ($buff = fread($in, 4096)) {
            fwrite($out, $buff);
        }
        @fclose($in);
        unlink($partFilePath); // Ensure .PART files are removed after successful assembly
    }
    fclose($out);

    // Perform checksum verification and color analysis only if all parts have been successfully assembled
    $finalFileChecksum = hash_file('sha256', $finalFilePath);
    if (isset($_POST['finalChecksum']) && $_POST['finalChecksum'] !== $finalFileChecksum) {
        unlink($finalFilePath); // Remove the final file if checksum verification fails
        die(json_encode(['error' => 'Final checksum verification failed.']));
    }

    // After successful assembly and verification, perform color analysis
    $colorResults = analyzeImageColors($finalFilePath);
    echo json_encode(["message" => "Upload and assembly complete.", "colors" => $colorResults]);
} else {
    echo json_encode(["message" => "Chunk $chunkNumber upload successful. Waiting for more chunks..."]);
}


function analyzeImageColors($filePath) { // function to analyze image colors
    $imageInfo = getimagesize($filePath);
    switch ($imageInfo['mime']) { // CHECK IF IMAGE
        case 'image/jpeg':
            $image = imagecreatefromjpeg($filePath);
            break;
        case 'image/png':
            $image = imagecreatefrompng($filePath);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($filePath);
            break;
        default:
            return []; // Unsupported image type
    }

    $width = imagesx($image); // Get image width
    $height = imagesy($image); // Get image height
    $colorFrequency = [];

    $dynamicSampleSize = sqrt($width * $height) / 250; // Dynamic sample size for diffrnect image sizes
    $xStep = max(1, round($width / $dynamicSampleSize)); // max - verify value is at least 1. round - make sure its a round number. determines how many pixels to skip.
    $yStep = max(1, round($height / $dynamicSampleSize));

    for ($x = 0; $x < $width; $x += $xStep) { // calculate the frequency of each color present 
        for ($y = 0; $y < $height; $y += $yStep) {
            $rgb = imagecolorat($image, $x, $y);
            $colors = imagecolorsforindex($image, $rgb);
            $key = sprintf('%03d-%03d-%03d', $colors['red'], $colors['green'], $colors['blue']);
            $colorFrequency[$key] = ($colorFrequency[$key] ?? 0) + 1;
        }
    }

    // Advanced color clustering - verify not using same color tones.
    $clusters = clusterColors($colorFrequency, sqrt($width * $height)/40);

    arsort($clusters); // sort by %
    $dominantColors = array_slice($clusters, 0, 5, true);
    $total = array_sum(array_column($dominantColors, 'count'));

    $results = [];
    foreach ($dominantColors as $color => $data) { // getting most popular from dominantcolors
        $percentage = ($data['count'] / $total) * 100;
        $hexColor = sprintf("#%02x%02x%02x", $data['color']['r'], $data['color']['g'], $data['color']['b']);
        $results[] = ['color' => $hexColor, 'percentage' => round($percentage, 2)];
    }
    
   // Sort the results by percentage in descending order
    usort($results, function ($item1, $item2) {
        return $item2['percentage'] <=> $item1['percentage'];
    });

    return $results;
}

function clusterColors($colorFrequency, $dynamicThreshold) { // verify not using same color tones. using dyanmicthersold for the colors threshold.
    $clusters = [];

    foreach ($colorFrequency as $color => $count) {
        list($r, $g, $b) = sscanf($color, '%03d-%03d-%03d');
        $isClustered = false;

        foreach ($clusters as &$cluster) {
            if (colorDistance(['r' => $r, 'g' => $g, 'b' => $b], $cluster['color']) < $dynamicThreshold) {
                $cluster['count'] += $count;
                $isClustered = true;
                break;
            }
        }

        if (!$isClustered) {
            $clusters[$color] = ['color' => ['r' => $r, 'g' => $g, 'b' => $b], 'count' => $count];
        }
    }
    

    return $clusters;
}


function colorDistance($color1, $color2) { // part of color clustring function to define colors diffrense 
    return sqrt(pow($color1['r'] - $color2['r'], 2) + pow($color1['g'] - $color2['g'], 2) + pow($color1['b'] - $color2['b'], 2));
}
?>
