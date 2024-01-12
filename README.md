# Bitcoin Address Balance Checker
The scripts collectively form a web application focused on checking the balance of Bitcoin addresses. Users can provide a list of addresses stored in a text file, and the system processes each address, saving those with balances exceeding 0.01 BTC and 10 BTC into separate files. 

Importantly, the application incorporates measures to respect the rate limits imposed by the Blockchain API during the address balance retrieval process. This ensures the responsible and compliant use of the external API service while effectively managing and categorizing Bitcoin addresses based on their balances.

# v2
- Added storedata.php to store address into sqlite database
- update `$db = new SQLite3('address.db')` with your database location
- update `$file_path = 'database/data.txt-large'` with your database source location
- point browser to storedata.php and click start to begin inserting address into database
- the process will loop for a certain number of addresses until done



## License

- MIT
