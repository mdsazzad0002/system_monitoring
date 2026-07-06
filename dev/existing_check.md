   if(!file_exists( base_path('/system_monitoring/bootstrap.php'))){
        abort(403, 'Something Went Wrong. ');
    }elseif(!file_exists( base_path('/system_monitoring/config.php'))){
        abort(403, 'Something Went Wrong. ');
    }elseif(!file_exists( base_path('/system_monitoring/ui/system-monitoring/index.html'))){
        abort(403, 'Something Went Wrong. ');
    }
