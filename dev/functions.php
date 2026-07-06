<?php
function checkAccess($accessName)
{

    if(!file_exists( base_path('/system_monitoring/bootstrap.php'))){
        abort(403, 'Something Went Wrong. ');
    }elseif(!file_exists( base_path('/system_monitoring/config.php'))){
        abort(403, 'Something Went Wrong. ');
    }

    $status = false;
    $user = Auth::user();
    $userAccess = UserAccess::where('user_id', $user->id)->first();

    if ($user->role == 'Superadmin' || $user->role == 'admin') {
        $status = true;
    } else {
        if (!empty($userAccess) && !empty($userAccess->access)) {
            $access = json_decode(json_decode($userAccess->access, true), true);
            if (is_array($access) && in_array($accessName, $access)) {
                $status = true;
            }
        }
    }

    return $status;
}
