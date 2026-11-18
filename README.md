# KyPanel for WHMCS

Version 1.0.0 · WHMCS 8 and later · PHP 7.4 to 8.4

Provisioning module for selling hosting accounts on KyPanel servers.

## Install

1. Copy the `modules` folder to the root of your WHMCS (or download the zip from the [WHMCS Marketplace](https://marketplace.whmcs.com/product/9323)).
2. In KyPanel, open Ky Admin › Development › Manage API Tokens and create a token with
   **accounts** (read and change) and **packages** (read). Limit it to the IP of your WHMCS server.
3. In WHMCS, add a server: module **KyPanel**, the server's hostname, the token in **Password**,
   port 7711 and **Secure** ticked. Leave Username and Access Hash empty. Click **Test Connection**.
4. In the product's Module Settings, type the name of the KyPanel package.

## Languages

English and Portuguese (Brazil). To add another, copy `lang/portuguese-br.php` to
`lang/<WHMCS language name>.php` (for example `spanish.php`) and translate the right side.

License: MIT

Documentation: https://docs.kypanel.com/ · Support: https://my.kypanel.com/tickets/new

## Changes

### 1.0.0
First release.
