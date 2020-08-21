#!/usr/bin/env bash

if [ -z "$1" ]
  then
    YEAR=$(date +%Y)
  else
    YEAR=$1
fi

if [ -z "$2" ]
  then
    WEEK=$(date +%V)
  else
    WEEK=$2
fi

CSV=$(./csv.rc ${YEAR} ${WEEK})
TOKEN=$(./get-domo-token.rc)
DOMO_DATASET_ID=61d863d4-698a-4658-95e0-d4794e089488

echo "${CSV}"

curl \
  --header "Authorization:bearer ${TOKEN}" \
  --header "Content-Type: text/csv" \
  --url "https://api.domo.com/v1/datasets/${DOMO_DATASET_ID}/data?updateMethod=REPLACE" \
  --data "${CSV}" \
  -X PUT