# Cloudflare DNS Extension for Plesk 17.8+
![Cloudflare](cloudflareplesk.png)  

![Cloudflare settings](src/_meta/screenshots/1.png)

## Requirements
- PHP 7.1+ 
- Plesk 17.8 or higher
- One or more domains hosted with Cloudflare and API access

## How to install

- Navigate to the releases tab: https://github.com/firstred/plesk-cloudflare/releases/latest
- Download the extension
- Add the following to your [panel.ini](https://docs.plesk.com/en-US/onyx/administrator-guide/plesk-administration/panelini-configuration-file.78509/) file:

    ```
    [ext-catalog]
    
    extensionUpload = true
    ```
    This makes sure that you can upload extension on the extension page.
- Navigate to the `Extensions` page, then `My Extensions`. Here you can upload the extension zip.
- Cloudflare should now be visible in your extension list.

## How to configure

The module needs your Cloudflare email and an API key to use for the API.  
You can generate a new private key in your Cloudflare Config Panel.

## How to build

Install the dependencies first with composer. Unlike a regular composer package the `vendor` folder
can be found in the subdirectory `src/plib`. This is the folder that eventually ends up on Plesk.
Packing up the `src/plib` folder is enough to upload the module.  
This is what an extension's file structure should
look like: https://docs.plesk.com/en-US/onyx/extensions-guide/plesk-extensions-basics/extension-structure.71076/
