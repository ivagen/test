#!/bin/bash

# Stopping all containers
docker-compose stop

# Delete all containers
docker rm $(docker ps -a -q)

# Delete all images
docker rmi $(docker images -q)