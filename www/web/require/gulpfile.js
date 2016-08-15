var gulp = require('gulp');
var concat = require('gulp-concat');
var cleanCss = require('gulp-clean-css');

gulp.task('pack-js', function () {
    return gulp.src([
        'bower_components/jquery/dist/jquery.min.js',
        'bower_components/bootstrap/dist/js/bootstrap.min.js',
        'bower_components/angular/angular.min.js',
        'bower_components/angular-modal-service/dst/angular-modal-service.min.js',
        'bower_components/angular-websocket/dist/angular-websocket.min.js',
        '../source/js/main.js'
    ])
        .pipe(concat('main.js'))
        .pipe(gulp.dest('../js'));
});

gulp.task('pack-css', function () {
    return gulp.src([
        'bower_components/bootstrap/dist/css/bootstrap.min.css',
        'bower_components/bootstrap/dist/css/bootstrap-theme.min.css',
        '../source/css/site.css'
    ])
        .pipe(concat('style.css'))
        .pipe(cleanCss())
        .pipe(gulp.dest('../css'));
});

gulp.task('fonts', function() {
    return gulp.src('bower_components/bootstrap/dist/fonts/**/*')
        .pipe(gulp.dest('../fonts'))
})

gulp.task('default', ['pack-js', 'pack-css', 'fonts']);