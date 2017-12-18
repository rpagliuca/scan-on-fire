#!/bin/bash

for file in `ls -1 in/*.pdf`; do
    date=`echo $file | awk -F/ '{print $2}' | awk -F. '{print $1}'`
    ./split-pdf.php $date
done
