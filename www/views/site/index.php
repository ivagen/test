<?php
/* @var $this yii\web\View */
$this->title = 'List of things to rest';
?>

<div class="container">
    <div class="page-header">
        <h1>List of things to rest</h1>
    </div>

    <table class="table table-hover" ng-controller="ListController">
        <thead>
        <tr>
            <th>#</th>
            <th>Denomination</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <tr ng-repeat="row in rows">
            <th scope="row">{{row.id}}</th>
            <td>{{row.name}}</td>
            <td>
                <span class="glyphicon glyphicon-edit cursor-pointer" ng-click="edit(row.id, row.name)">&nbsp;</span>
                <span class="glyphicon glyphicon-remove-circle cursor-pointer" ng-click="remove(row.id)">&nbsp;</span>
            </td>
        </tr>
        </tbody>
    </table>
</div>

<div class="container" ng-controller="PageController">
    <a class="btn btn-default" href ng-click="show()">Add row</a>

    <script type="text/ng-template" id="modal.html">
        <div class="modal fade">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                        <h4 class="modal-title">Add a new purchase</h4>
                    </div>
                    <form class="form-horizontal" ng-submit="submit()">
                        <div class="modal-body">
                            <div class="form-group">
                                <label for="name" class="col-sm-2 control-label">Name</label>
                                <div class="col-sm-10">
                                    <input type="text" class="form-control" id="name" placeholder="Name"
                                           ng-model="name" required>
                                    <input type="hidden" ng-model="id">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <input type="submit" id="submit" class="btn btn-primary" value="{{action}}"/>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </script>
</div>

<toaster-container toaster-options="{'time-out': 1000}"></toaster-container>
