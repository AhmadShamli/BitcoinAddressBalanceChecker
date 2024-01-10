const maxRetries = 5;
const retryDelaySeconds = 30;
const delaySeconds = 12;
let processing = false;
let nextgetaddresstime = 0;
let currentrunning = 0;
let countdownInterval;
let lastline = 0;
let totaladdress = 0;
let addressperrun = 0;
let totalthisrun = 0;

function updateStats() {
    const stats = document.getElementById('stats');
    stats.textContent = `Last Line: ${lastline} | Total Address: ${totaladdress} | Done: ${roundNumber(lastline/totaladdress*100, 4)}%`;
}

function roundNumber(num, scale) {
    if(!("" + num).includes("e")) {
        return +(Math.round(num + "e+" + scale)  + "e-" + scale);
    } else {
        var arr = ("" + num).split("e");
        var sig = ""
        if(+arr[1] + scale > 0) {
            sig = "+";
        }
        return +(Math.round(+arr[0] + "e" + sig + (+arr[1] + scale)) + "e-" + scale);
    }
}

function curentTime(){
    const currentTime = new Date();
    function beginZero(num) {
        if (num < 10) {
            return "0" + num;
        } else {
            return num;
        }
    }
    return currentTime.getFullYear() + "-" + beginZero(currentTime.getMonth()+1) +
        "-" + beginZero(currentTime.getDate()) + " " + beginZero(currentTime.getHours()) +
        ":" + beginZero(currentTime.getMinutes()) + ":" + beginZero(currentTime.getSeconds());
}
function sendmessage(message, messageType="message") {
    removePreLines('message',20);
    const messageDiv = document.getElementById('message');

    message = curentTime() + " : " + message;
    if (messageType === "error") {
        messageDiv.innerHTML = "<pre style='color:red'>" + message + "</pre>" + messageDiv.innerHTML;
        console.error(message);
    } else if (messageType === "warning") {
        messageDiv.innerHTML = "<pre style='color:orange'>" + message + "</pre>" + messageDiv.innerHTML;
        console.warn(message);
    } else if (messageType === "message") {
        messageDiv.innerHTML = "<pre>" + message + "</pre>" + messageDiv.innerHTML;
        console.log(message);
    }
}

function startCountdown() {
    stopCountdown(); // Clear existing interval if any

    let countdownElement = document.getElementById('countdown');
    let remainingTime = delaySeconds;

    countdownInterval = setInterval(function () {
        countdownElement.textContent = `Next query: ${remainingTime} seconds`;
        nextgetaddresstime = remainingTime
        remainingTime--;

        //if (remainingTime < 0) {
        //    stopCountdown();
        //}
    }, 1000); // Update every second
}

function stopCountdown() {
    clearInterval(countdownInterval);
    document.getElementById('countdown').textContent = '';
}

function resetTimer() {
    //lastgetaddresstime = new Date().getTime();
    nextgetaddresstime = delaySeconds;
    if (processing) {
        startCountdown();
    }
}

// function to remove bottom pre line inside div after n number of lines
function removePreLines(divId, n) {
    const div = document.getElementById(divId);
    const preLines = div.getElementsByTagName('pre');
    if (preLines.length > n) {
        div.removeChild(preLines[n]);
    }
}

function changeButton() {
    let button = document.getElementById('processButton');
    if (processing) {
        button.innerHTML = 'Stop';
    } else {
        button.textContent = 'Start Process';
    }
}

function startStopProcess() {
    const button = document.getElementById('processButton');

    if (!processing) {
        processing = true;
        changeButton();
        sendmessage("Starting")
        go();
    } else {
        processing = false;
        stopCountdown();
        changeButton();
        sendmessage("Process stopped.");
    }
}

function go() {
    function checkProcessing() {
        console.log("processing:" + processing + " currentrunning: " + currentrunning + " nextgetaddresstime: " + nextgetaddresstime);
        if (processing && currentrunning === 0 && nextgetaddresstime <= 0) {
            currentrunning++;
            updateStats();
            resetTimer();
            getAddress();
        }
        setTimeout(checkProcessing, 1000);
    }

    checkProcessing();
}

function getAddress() {
    sendmessage("Getting address")
    $.ajax({
        url: 'address.php?getaddress',
        type: 'GET',
        success: function(response) {
            console.log('Server response:', response);
            sendmessage("Addresses received.");
            resetTimer();
            lastline = response.last_processed_line;
            totaladdress = response.total_addresses;
            addressperrun = response.address_count;
            updateStats();
            getBalancesFromBlockchainInfo(response);
        },
        error: function(error) {
            currentrunning--;
            resetTimer();
            processing = false;
            sendmessage("Error: " + error.responseText, 'error');
            return false;
        }
    });
}

function processAddressesFromJson(dataJson) {
    sendmessage("Converting received json data.");
    try {
        const objdata = JSON.parse(dataJson);

        getBalancesFromBlockchainInfo(objdata);

    } catch (error) {
        sendmessage('An error occurred: ' + error.message, 'error');
    }
}

function getBalancesFromBlockchainInfo(objdata) {
    sendmessage("Getting address balance.");
    const addresses = objdata.addresses;
    lastline = objdata.last_processed_line;
    $.ajax({
        url: 'https://blockchain.info/multiaddr',
        type: 'GET',
        data: { active: addresses.join('|'), n:0 },
        success: function(response) {
            sendmessage("Response received from Blockchain.info");
            resetTimer();
            processingBalance(response,objdata);
        },
        error: function(error) {
            currentrunning--;
            resetTimer();
            sendmessage('Error getting balances from Blockchain.info: ${error.responseText}','error');
        }
    });
}

function processingBalance(data,objdata) {
    const processedAddresses = [];
    sendmessage("Processing address balance.");
    data.addresses.forEach(addr => {
        const balance = addr.final_balance / 1e8;

        if (balance < 0.01) {
            // Ignore addresses below 0.01 BTC
            return;
        } else if (balance < 1) {
            processedAddresses.push({ address: addr.address, category: 'below_1btc' });
        } else {
            processedAddresses.push({ address: addr.address, category: 'above_1btc' });
        }
    });

    sendProcessedAddresses([processedAddresses,{address_count:objdata.address_count,last_processed_line:objdata.last_processed_line}]);
    sendmessage('Addresses processed successfully');
}

function sendProcessedAddresses(processedAddresses) {
    sendmessage("Sending data to server.");
    $.ajax({
        url: 'address.php',
        type: 'POST',
        data: { processedAddresses: JSON.stringify(processedAddresses) },
        success: function(response) {
            console.log(response);
            sendmessage('Data sent successfully.');
            totalthisrun += addressperrun;
            currentrunning--;
        },
        error: function(error) {
            currentrunning--;
            sendmessage('Error sending processed addresses: ' + error.responseText,'error');
        }
    });
}
