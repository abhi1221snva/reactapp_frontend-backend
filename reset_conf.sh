#!bin/bash

exten=$1

#Find local channel and hangup
x1=$(asterisk -rx "confbridge list ${exten}" | grep Local | cut -d " " -f1)
#echo $x1;
y1="asterisk -rx 'hangup request ${x1}'"
eval $y1;

#Find SIP channel and hangup
x2=$(asterisk -rx "confbridge list ${exten}" | grep SIP | cut -d " " -f1)
#echo $x2;
y2="asterisk -rx 'hangup request $x2'"
eval $y2;
