<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chunked Image Upload with Total Progress</title>
    <style>
        /* CSS for style */
        body {
            margin: 5%;
            font-family: Arial;
            text-align: center;
        }
        input, button {
            font-size: 1.2rem;
            margin: 2vh;
            max-width: 90%;
        }
        .progressContainer {
            width: 100%;
            max-width: 600px;
            background-color: #ddd;
            margin-top: 10px;
            height: 20px;
            border-radius: 5px;
            overflow: hidden;
            margin: 15px auto;
        }
        .progressBar {
            width: 0%;
            height: 100%;
            background-color: #04AA6D;
            text-align: center;
            font-size: 80%;
            line-height: 20px;
            color: white;
            transition: width 0.5s ease-in-out;
        }
         /* CSS for disable upload button while uploading proccess */
        button:disabled {
            background-color: #cccccc;
            cursor: not-allowed;
        }
        #imageDisplay {
            max-width: 60%;
            max-height: 50vh;
            margin-top: 10px;
        }
        .colorDisplay {
            display: flex;
            flex-wrap: wrap;
            flex-direction: column;
        }
        .colorBox {
            color: black;
            text-align: center;
            line-height: 45px;
            margin: 5px;
            padding: 5px;
            font-size: 90%;
            border: 1px solid #000;
            border-radius: 5px;
        }
        #resultsContainer{
            display: flex;
            align-items: center;
            background: #f5f4f4;
            border-radius: 15px;
            margin: 15px;
        }
    </style>
</head>
<body>
    <h1>Upload Image in Chunks & Color Analyze</h1>
    <input type="file" id="fileInput" accept="image/*">
    <button id="uploadButton" onclick="uploadFile()">Upload</button>
    
    <div id="totalProgressContainer" class="progressContainer">
        <div id="totalProgressBar" class="progressBar">Total Progress: 0%</div>
    </div>
    <div id="chunkProgressContainer" class="progressContainer">
        <div id="chunkProgressBar" class="progressBar">Chunk Progress: 0%</div>
    </div>
    <p id="statusMessage"></p>
    <div id="resultsContainer">
        <img id="imageDisplay" style="display:none;"> <!-- Image display element -->
        <div id="colorDisplay" class="colorDisplay"></div> <!-- Color display container -->
    </div>
    
    <script>
        async function calculateSHA256Checksum(arrayBuffer) {
            const hashBuffer = await crypto.subtle.digest('SHA-256', arrayBuffer);
            const hashArray = Array.from(new Uint8Array(hashBuffer));
            return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        }

        function uploadFile() {
            const fileInput = document.getElementById('fileInput');
            const uploadButton = document.getElementById('uploadButton');
            const statusMessage = document.getElementById('statusMessage');
            const chunkProgressBar = document.getElementById('chunkProgressBar');
            const totalProgressBar = document.getElementById('totalProgressBar');
            const imageDisplay = document.getElementById('imageDisplay');
            const colorDisplay = document.getElementById('colorDisplay');

            imageDisplay.src = '';
            imageDisplay.style.display = 'none';
            colorDisplay.innerHTML = '';

            if (!fileInput.files.length) {
                statusMessage.innerText = 'Please select image to upload.';
                return;
            }

            const file = fileInput.files[0];
            const chunkSize = 1048576; // 1 MB
            let offset = 0;
            const totalChunks = Math.ceil(file.size / chunkSize);
            uploadButton.disabled = true;

            function updateChunkProgress(chunkNumber) {
                const chunkProgressPercent = Math.round((chunkNumber / totalChunks) * 100);
                chunkProgressBar.style.width = chunkProgressPercent + '%';
                chunkProgressBar.innerText = `Chunk Progress: ${chunkProgressPercent}%`;
            }

            function updateTotalProgress(bytesUploaded) {
                const totalProgressPercent = Math.min(100, Math.round((bytesUploaded / file.size) * 100));
                totalProgressBar.style.width = totalProgressPercent + '%';
                totalProgressBar.innerText = `Total Progress: ${totalProgressPercent}%`;
            }

            async function uploadChunk(chunk, chunkNumber) {
                const reader = new FileReader();
                reader.onload = async function(event) {
                    const arrayBuffer = event.target.result;
                    
                    // Calculate SHA256 checksum using SubtleCrypto
                    const chunkChecksum = await calculateSHA256Checksum(arrayBuffer);
                    
                    const formData = new FormData();
                    formData.append('file', new Blob([arrayBuffer]), file.name);
                    formData.append('chunkNumber', chunkNumber);
                    formData.append('fileName', file.name);
                    formData.append('checksum', chunkChecksum);
                    formData.append('totalChunks', totalChunks);

                    try {
                        const response = await fetch('upload.php', {
                            method: 'POST',
                            body: formData,
                        });

                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }

                        if (offset + chunkSize >= file.size) {
                            const responseJson = await response.json();
                            console.log(responseJson.message); // CHUNK UPLOAD STATUS TO CONSOLE LOG
                            if (responseJson.colors) {
                                imageDisplay.src = URL.createObjectURL(file);
                                imageDisplay.style.display = 'block';

                                colorDisplay.innerHTML = '';
                                responseJson.colors.forEach(colorInfo => {
                                    const hex = colorInfo.color;
                                    const r = parseInt(hex.slice(1, 3), 16);
                                    const g = parseInt(hex.slice(3, 5), 16);
                                    const b = parseInt(hex.slice(5, 7), 16);

                                    const colorDiv = document.createElement('div');
                                    colorDiv.classList.add("colorBox");
                                    colorDiv.style.backgroundColor = `rgb(${r}, ${g}, ${b})`;
                                    colorDiv.innerText = `RGB(${r}, ${g}, ${b}) - ${colorInfo.percentage}%`;

                                    colorDisplay.appendChild(colorDiv);
                                });
                            }
                        } else {
                            const textResponse = await response.text();
                            console.log(textResponse); // CHUNK UPLOAD STATUS TO CONSOLE LOG with text
                        }

                        updateTotalProgress(offset + chunkSize);
                        offset += chunkSize;
                        if (offset < file.size) {
                            loadNextChunk();
                        } else {
                            uploadButton.disabled = false;
                            statusMessage.innerText = 'Upload complete.';
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        uploadButton.disabled = false;
                        statusMessage.innerText = 'Error uploading file: ' + error.message;
                    }
                };
                reader.onerror = function(error) {
                    console.error('Error reading file:', error);
                    uploadButton.disabled = false;
                    statusMessage.innerText = 'Error reading file: ' + error.message;
                };
                reader.readAsArrayBuffer(chunk);
                updateChunkProgress(chunkNumber);
            }

            function loadNextChunk() {
                const chunk = file.slice(offset, Math.min(offset + chunkSize, file.size));
                uploadChunk(chunk, Math.ceil(offset / chunkSize) + 1);
            }

            statusMessage.innerText = '';
            totalProgressBar.style.width = '0%';
            totalProgressBar.innerText = 'Total Progress: 0%';
            chunkProgressBar.style.width = '0%';
            chunkProgressBar.innerText = 'Chunk Progress: 0%';

            loadNextChunk();
        }
    </script>
</body>
</html>
