wp-membership-bitpay-gateway
============================

A [BitPay][bitpay] payment gateway for the
[WPMU Membership plugin][wpmumembership] for [WordPress][wordpress].

Installation
------------

The contents of the membership/ tree of this respository needs to be overlaid
into the membership plugin's directory in your WordPress installation. If
standard path names are in use, this might be done like this:

$ git clone https://github.com/bitcoinerswithoutborders/wp-membership-bitpay-gateway.git
$ cd wp-membership-bitpay-gateway
# $WP_HOME is the base directory of your WordPress installation.
$ sudo cp -R membership ($WP_HOME)/wp-content/plugins/membership

Now, activate the gateway in WordPress:
Membership->Payment Gateways->BitPay->Activate

Documentation
-------------

Documentation can be found at [code.gogulski.com](http://code.gogulski.com/).

Requirements
------------

### PHP requirements:
* PHP5
* cURL support  
* JSON support
* SSL support (if you want BitPay IPN callbacks, which are generally a Good
Idea.)

Authors
-------

* [Mike Gogulski](http://github.com/mikegogulski) -
 [Bitcoiners Without Borders][bwb]

Developed by [Bitcoiners without Borders][bwb] on behalf of the
[Bitcoin Foundation][bitcoinfoundation].

Credits
-------

wp-membership-bitpay-gateway incorporates code and ideas from:

* [bitpay/php-client][bitpayphpclient] by [Rich Morgan][ionux]

License
-------

wp-membership-bitpay-gateway is free and unencumbered public domain software.
For more information, see [http://unlicense.org/] or the accompanying
UNLICENSE file.

[bitpay]:		https://bitpay.com/
[wpwmumembership]:	
[wordpress]:		https://wordpress.org/
[bwb]:			http://bwb.is/
[bitcoinfoundation]:	https://bitcoinfoundation.org/
[bitpayphplib]:		https://github.com/bitpay/php-client
[ionux]:		https://github.com/ionux
