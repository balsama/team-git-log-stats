#!/usr/bin/env bash
# 2019
y=2019
q=1
i=1
while [ $i -le 13 ]; do
  php ./scripts/send-to-sheets.php $y $i contributors-$((y))-q$((q)).yml
  i=$((i+1))
done

q=$((q+1))
while [ $i -le 26 ]; do
  php ./scripts/send-to-sheets.php $y $i contributors-$((y))-q$((q)).yml
  i=$((i+1))
done

q=$((q+1))
while [ $i -le 39 ]; do
  php ./scripts/send-to-sheets.php $y $i contributors-$((y))-q$((q)).yml
  i=$((i+1))
done

q=$((q+1))
while [ $i -le 52 ]; do
  php ./scripts/send-to-sheets.php $y $i contributors-$((y))-q$((q)).yml
  i=$((i+1))
done

# 2020
y=2020
q=1
i=1
while [ $i -le 13 ]; do
  php ./scripts/send-to-sheets.php $y $i contributors-$((y))-q$((q)).yml
  i=$((i+1))
done

q=$((q+1))
while [ $i -le 26 ]; do
  php ./scripts/send-to-sheets.php $y $i contributors-$((y))-q$((q)).yml
  i=$((i+1))
done

q=$((q+1))
while [ $i -le 39 ]; do
  php ./scripts/send-to-sheets.php $y $i contributors-$((y))-q$((q)).yml
  i=$((i+1))
done

q=$((q+1))
while [ $i -le 52 ]; do
  php ./scripts/send-to-sheets.php $y $i contributors-$((y))-q$((q)).yml
  i=$((i+1))
done

# 2021
y=2021
q=1
i=1
while [ $i -le 13 ]; do
  php ./scripts/send-to-sheets.php $y $i contributors-$((y))-q$((q)).yml
  i=$((i+1))
done

q=$((q+1))
while [ $i -le 26 ]; do
  php ./scripts/send-to-sheets.php $y $i contributors-$((y))-q$((q)).yml
  i=$((i+1))
done