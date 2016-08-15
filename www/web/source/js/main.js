var app = angular.module('app', ['angularModalService', 'ngWebSocket']);

app.factory('Rows', function ($websocket) {
    var dataStream = $websocket('ws://' + document.domain + ':8047/websocket');
    var data = '';

    dataStream.onMessage(function (message) {

        if (message.data !== 'pong') {
            data = message.data;
        }
    });

    var methods = {
        getData: function () {
            return data;
        },
        ping: function () {
            dataStream.send('ping');
        }
    };

    /**
     * For refresh connect
     */
    setInterval(function () {
        methods.ping()
    }, 10000);

    return methods;
});

app.factory('Actions', function ($http) {
    return {
        get: function () {
            var data = $http({
                method: 'GET',
                url: '/index.php?r=site/get',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'}
            }).then(function successCallback(response) {
                return response.data.rows;
            });

            return data;
        },
        create: function (name) {
            var params = $.param({
                Items: {
                    name: name
                }
            });

            $http({
                method: 'POST',
                url: '/index.php?r=site/create',
                data: params,
                headers: {'Content-Type': 'application/x-www-form-urlencoded'}
            }).then(function successCallback(response) {
                console.log(response);
            });
        },
        edit: function (id, name) {
            var params = $.param({
                Items: {
                    name: name
                }
            });

            $http({
                method: 'POST',
                url: '/index.php?r=site/update&id=' + id,
                data: params,
                headers: {'Content-Type': 'application/x-www-form-urlencoded'}
            }).then(function successCallback(response) {
                console.log(response);
            });
        },
        remove: function (id) {
            $http({
                method: 'POST',
                url: '/index.php?r=site/delete&id=' + id,
                headers: {'Content-Type': 'application/x-www-form-urlencoded'}
            }).then(function successCallback(response) {
                console.log(response);
            });
        }
    };
});

app.controller('ListController', function ListController($scope, ModalService, Actions, Rows) {

    Actions.get().then(function (rows) {
        $scope.rows = rows;
    });

    var hash = '';

    setInterval(function () {
        var data = Rows.getData();

        if (data !== hash) {
            var json = JSON.parse(data);
            $scope.rows = json.rows;
            hash = data;
        }
    }, 500);

    $scope.edit = function (id, name) {
        ModalService.showModal({
            templateUrl: 'modal.html',
            controller: "ModalController",
            inputs: {
                id: id,
                name: name,
                action: 'Update'
            }
        }).then(function (modal) {
            modal.element.modal();
        });
    };

    $scope.remove = function (id) {
        Actions.remove(id);
    };
});

app.controller('PageController', function ($scope, ModalService) {

    $scope.show = function () {
        ModalService.showModal({
            templateUrl: 'modal.html',
            controller: "ModalController",
            inputs: {
                id: '',
                name: '',
                action: 'Create'
            }
        }).then(function (modal) {
            modal.element.modal();
        });
    };
});

app.controller('ModalController', function ($scope, $element, Actions, id, name, action) {

    $scope.id = id;
    $scope.name = name;
    $scope.action = action;

    $scope.submit = function () {

        if ($scope.name) {

            if ($scope.id) {
                Actions.edit($scope.id, $scope.name);
            } else {
                Actions.create($scope.name);
            }

            $scope.id = '';
            $scope.name = '';
            $element.modal('hide');
        }
    };
});





