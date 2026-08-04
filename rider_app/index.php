<?php
// You can add any necessary PHP session/auth logic here if needed.
// For example, checking if a rider is logged in.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider - Scan Delivery QR</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f0f2f5;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            color: #1c1e21;
        }
        .container {
            width: 100%;
            max-width: 500px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            text-align: center;
            padding: 25px;
            box-sizing: border-box;
        }
        h1 {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }
        p {
            color: #606770;
            margin-bottom: 25px;
        }
        #qr-reader {
            width: 100%;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
            display: none; /* Initially hidden */
        }
        #start-scan-btn {
            background-color: #1877f2;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.2s;
            width: 100%;
        }
        #start-scan-btn:hover {
            background-color: #166fe5;
        }
        .result-box {
            margin-top: 20px;
            padding: 15px;
            border-radius: 8px;
            font-weight: bold;
            display: none; /* Initially hidden */
        }
        .result-box.success {
            background-color: #eafaf1;
            color: #27ae60;
            border: 1px solid #c3e6cb;
        }
        .result-box.error {
            background-color: #fff5f5;
            color: #e74c3c;
            border: 1px solid #f5c6cb;
        }
        .spinner {
            border: 4px solid rgba(0, 0, 0, 0.1);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border-left-color: #1877f2;
            margin: 20px auto;
            animation: spin 1s ease infinite;
            display: none;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
    <!-- QR Code Scanning Library -->
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
</head>
<body>

    <div class="container">
        <h1>Confirm Delivery</h1>
        <p>Scan the customer's QR code to confirm that the order has been delivered.</p>

        <div id="qr-reader"></div>
        <button id="start-scan-btn">Start Camera Scan</button>

        <div id="loading-spinner" class="spinner"></div>
        <div id="result-box" class="result-box"></div>
    </div>

    <script>
        const startScanBtn = document.getElementById('start-scan-btn');
        const qrReaderDiv = document.getElementById('qr-reader');
        const resultBox = document.getElementById('result-box');
        const loadingSpinner = document.getElementById('loading-spinner');

        function onScanSuccess(decodedText, decodedResult) {
            // Stop scanning
            html5QrcodeScanner.clear();
            qrReaderDiv.style.display = 'none';
            startScanBtn.style.display = 'block';

            console.log(`Scan result: ${decodedText}`);
            resultBox.style.display = 'none';
            loadingSpinner.style.display = 'block';

            try {
                const qrData = JSON.parse(decodedText);
                if (!qrData.delivery_id || !qrData.token) {
                    throw new Error('Invalid QR code content.');
                }

                // This is where you would get the rider's JWT token
                // For this example, I'll use a placeholder.
                // In a real app, you'd get this from a cookie or local storage after login. Let's try to get it from a cookie.
                const getCookie = (name) => {
                    const value = `; ${document.cookie}`;
                    const parts = value.split(`; ${name}=`);
                    if (parts.length === 2) return parts.pop().split(';').shift();
                };
                const riderJwtToken = getCookie('rider_jwt'); // Assuming the JWT is stored in a cookie named 'rider_jwt'

                if (!riderJwtToken) {
                    loadingSpinner.style.display = 'none';
                    resultBox.style.display = 'block';
                    resultBox.className = 'result-box error';
                    resultBox.textContent = 'Error: Rider not logged in. Please login first.';
                    return;
                }
                fetch('../modules/rider/rider_qr_confirm_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${riderJwtToken}`
                    },
                    body: JSON.stringify({
                        delivery_id: qrData.delivery_id,
                        qr_code: decodedText // Send the raw QR content
                    })
                })
                .then(response => response.json())
                .then(data => {
                    loadingSpinner.style.display = 'none';
                    resultBox.style.display = 'block';
                    if (data.success) {
                        resultBox.className = 'result-box success';
                        resultBox.textContent = `Success! Order #${data.data.order_id} confirmed.`;
                    } else {
                        resultBox.className = 'result-box error';
                        resultBox.textContent = `Error: ${data.message}`;
                    }
                })
                .catch(error => {
                    loadingSpinner.style.display = 'none';
                    resultBox.style.display = 'block';
                    resultBox.className = 'result-box error';
                    resultBox.textContent = 'API request failed. Check console for details.';
                    console.error('API Error:', error);
                });

            } catch (e) {
                loadingSpinner.style.display = 'none';
                resultBox.style.display = 'block';
                resultBox.className = 'result-box error';
                resultBox.textContent = 'Failed to parse QR code. Is it a valid delivery QR?';
                console.error(e);
            }
        }

        const html5QrcodeScanner = new Html5QrcodeScanner("qr-reader", { fps: 10, qrbox: 250 });

        startScanBtn.addEventListener('click', () => {
            qrReaderDiv.style.display = 'block';
            startScanBtn.style.display = 'none';
            resultBox.style.display = 'none';
            html5QrcodeScanner.render(onScanSuccess);
        });
    </script>

</body>
</html>