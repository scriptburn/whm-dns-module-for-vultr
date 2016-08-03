# whm-dns-module-for-vultr
Use vultr.com instance as dns server for your cpanel based hosting

This script uses WHM API and Vultr.com API to Sync your WHM hosted Domain's Dns records On vultr.com dns system.

That means you can use vultr.com 's DNS servers and offload all DNS queries to there servers 

You just need the vultr.com's API key


## Install Instruction

**1** Copy files to any folder on your server;

**2** Then chown -R root:root /folder/where/you/copied/files;

**3** chmod -R 755 /folder/where/you/copied/files;

**4** cd /folder/where/you/copied/files;

**5** open config.php and put your vultr.com API key where it says 'enter your api key here'

**6** ./install.sh;


That is all

Now you can test if script is working correctly by creating a domain from WHM and then login to your vultr.com account and go to DNS section you will see the DNS entries of your Domain.

Now if you do not see DNS entries you can open init.php and set SCB_DEBUG to 1 and then start again and check error_log