#!/bin/bash
####################
CUR_DIR=$(pwd)
BASEDIR=$(dirname $0)
cd $BASEDIR
BASEDIR=$(pwd)

BUILD_FILENAME="smartling_connector.zip"

TMP_BUILD_DIR=/tmp
SMARTLING_BUILD_DIR=$TMP_BUILD_DIR/smartling-builds

# remove old build
rm -rf $BASEDIR/$BUILD_FILENAME

# create temporary build directory
rm -rf $SMARTLING_BUILD_DIR
mkdir $SMARTLING_BUILD_DIR

cp -r $BASEDIR/* $SMARTLING_BUILD_DIR
# remove dev dependencies

cd $SMARTLING_BUILD_DIR

rm -rf ./inc/third-party/*

# place minified dependencies
unzip ./dependencies.zip -d ./inc/third-party/

rm -f ./*.zip
rm -f ./*.log
rm -f ./composer*
rm -Rf ./*.sh
rm -Rf ./*.sql
rm -Rf ./phpunit*
rm -Rf ./tests
rm -Rf ./upload

zip -9 ./$BUILD_FILENAME -r ./*

echo "#$BASEDIR#"

mv ./$BUILD_FILENAME $BASEDIR/

cd $CUR_DIR

rm -rf $SMARTLING_BUILD_DIR

