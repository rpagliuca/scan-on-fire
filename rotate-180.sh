#!/bin/bash
echo $1
pdftk $1 cat 1-enddown output /tmp/$1
mv /tmp/$1 $1
