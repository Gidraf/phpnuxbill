<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/
$step = _req('step', 0);
$otpPath = $CACHE_PATH . File::pathFixer('/forgot/');

if ($step == '-1') {
    $_COOKIE['forgot_username'] = '';
    setcookie('forgot_username', '', time() - 3600, '/');
    $step = 0;
}

if (!empty($_COOKIE['forgot_username']) && in_array($step, [0, 1])) {
    $step = 1;
    $_POST['username'] = $_COOKIE['forgot_username'];
}

if ($step == 1) {
    $identifier = trim(_post('username'));
    if (!empty($identifier)) {
        $ui->assign('username', $identifier);
        if (!file_exists($otpPath)) {
            mkdir($otpPath);
        }
        $lookupPhone = Lang::phoneFormat($identifier);
        $user = ORM::for_table('tbl_customers')
            ->selects(['username', 'phonenumber', 'email'])
            ->where_any_is([
                ['username' => $identifier],
                ['email' => $identifier],
                ['phonenumber' => $identifier],
                ['phonenumber' => $lookupPhone],
            ])->find_one();
        if ($user) {
            $username = $user['username'];
            $ui->assign('username', $username);
            setcookie('forgot_username', $username, time() + 3600, '/');
            $otpPath .= sha1($username . $db_pass) . ".txt";
            if (file_exists($otpPath) && time() - filemtime($otpPath) < 600) {
                $sec = time() - filemtime($otpPath);
                $ui->assign('notify_t', 's');
                $ui->assign('notify', Lang::T("Verification Code already Sent to Your Phone/Email/Whatsapp, please wait")." $sec seconds.");
            } else {
                $via = $config['user_notification_reminder'];
                $otp = mt_rand(100000, 999999);
                file_put_contents($otpPath, $otp);
                if ($via == 'sms' || $via == 'both') {
                    Message::sendSMS($user['phonenumber'], $config['CompanyName'] . " C0de: $otp");
                }
                if ($via == 'wa' || $via == 'both') {
                    Message::sendWhatsapp($user['phonenumber'], $config['CompanyName'] . " C0de: $otp");
                }
                if (!empty($user['email'])) {
                    $emailSent = Message::sendEmail(
                        $user['email'],
                        $config['CompanyName'] . Lang::T("Your Verification Code") . ' : ' . $otp,
                        Lang::T("Your Verification Code") . ' : <b>' . $otp . '</b>'
                    );
                    if (!$emailSent) {
                        _log('Forgot-password email failed for username ' . $username);
                    }
                }
                $ui->assign('notify_t', 's');
                $ui->assign('notify', Lang::T("If your Username is found, Verification Code has been Sent to Your Phone/Email/Whatsapp"));
            }
        } else {
            // Username not found
            $ui->assign('notify_t', 's');
            $ui->assign('notify', Lang::T("If your Username is found, Verification Code has been Sent to Your Phone/Email/Whatsapp") . ".");
        }
    } else {
        $step = 0;
    }
} else if ($step == 2) {
    // CVPAP: verify the code (typed OR from an emailed link — _req accepts
    // GET) and show a "choose your new password" form instead of generating
    // a random password on screen
    $username = _req('username');
    $otp_code = _req('otp_code');
    if (!empty($username) && !empty($otp_code)) {
        $otpPath .= sha1($username . $db_pass) . ".txt";
        if (file_exists($otpPath) && time() - filemtime($otpPath) <= 1200) {
            $otp = file_get_contents($otpPath);
            if ($otp == $otp_code) {
                $ui->assign('username', $username);
                $ui->assign('otp_code', $otp_code);
                $ui->assign('_title', Lang::T('Change Password'));
                $ui->display('customer/forgot-set-password.tpl');
                exit();
            } else {
                r2(getUrl('forgot&step=1'), 'e', Lang::T('Invalid Username or Verification Code'));
            }
        } else {
            if (file_exists($otpPath)) {
                unlink($otpPath);
            }
            r2(getUrl('forgot&step=1'), 'e', Lang::T('Invalid Username or Verification Code'));
        }
    } else {
        r2(getUrl('forgot&step=1'), 'e', Lang::T('Invalid Username or Verification Code'));
    }
} else if ($step == 3) {
    // CVPAP: save the customer-chosen password (token consumed here)
    $username = _post('username');
    $otp_code = _post('otp_code');
    $password = _post('password');
    $cpassword = _post('cpassword');
    $otpPath .= sha1($username . $db_pass) . ".txt";
    $valid = !empty($username) && !empty($otp_code)
        && file_exists($otpPath) && time() - filemtime($otpPath) <= 1200
        && file_get_contents($otpPath) == $otp_code;
    if (!$valid) {
        if (file_exists($otpPath)) {
            unlink($otpPath);
        }
        r2(getUrl('forgot&step=1'), 'e', Lang::T('Invalid Username or Verification Code'));
    }
    if (!Validator::Length($password, 36, 5)) {
        $ui->assign('username', $username);
        $ui->assign('otp_code', $otp_code);
        $ui->assign('notify', Lang::T('Password should be between 6 to 35 characters'));
        $ui->assign('notify_t', 'd');
        $ui->assign('_title', Lang::T('Change Password'));
        $ui->display('customer/forgot-set-password.tpl');
        exit();
    }
    if ($password != $cpassword) {
        $ui->assign('username', $username);
        $ui->assign('otp_code', $otp_code);
        $ui->assign('notify', Lang::T('Passwords does not match'));
        $ui->assign('notify_t', 'd');
        $ui->assign('_title', Lang::T('Change Password'));
        $ui->display('customer/forgot-set-password.tpl');
        exit();
    }
    $user = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
    if ($user) {
        $user->password = $password;
        $user->save();
    }
    unlink($otpPath);
    setcookie('forgot_username', '', time() - 3600, '/');
    r2(getUrl('login'), 's', Lang::T('Password changed successfully, you can login now'));
} else if ($step == 7) {
    $find = _post('find');
    $step = 6;
    if (!empty($find)) {
        $via = $config['user_notification_reminder'];
        if ($via == 'email') {
            $via = 'sms';
        }
        if (!file_exists($otpPath)) {
            mkdir($otpPath);
        }
        $otpPath .= sha1($find . $db_pass) . ".txt";
        $users = ORM::for_table('tbl_customers')->selects(['username', 'phonenumber', 'email'])->where('phonenumber', $find)->find_array();
        if ($users) {
            // prevent flooding only can request every 10 minutes
            if (!file_exists($otpPath) || (file_exists($otpPath) && time() - filemtime($otpPath) >= 600)) {
                $usernames = implode(", ", array_column($users, 'username'));
                if ($via == 'sms') {
                    Message::sendSMS($find, Lang::T("Your username for") . ' ' . $config['CompanyName'] . "\n" . $usernames);
                } else {
                    Message::sendWhatsapp($find, Lang::T("Your username for") . ' ' . $config['CompanyName'] . "\n" . $usernames);
                }
                file_put_contents($otpPath, time());
            }
            $ui->assign('notify_t', 's');
            $ui->assign('notify', Lang::T("Usernames have been sent to your phone/Whatsapp") . " $find");
            $step = 0;
        } else {
            $users = ORM::for_table('tbl_customers')->selects(['username', 'phonenumber', 'email'])->where('email', $find)->find_array();
            if ($users) {
                // prevent flooding only can request every 10 minutes
                if (!file_exists($otpPath) || (file_exists($otpPath) && time() - filemtime($otpPath) >= 600)) {
                    $usernames = implode(", ", array_column($users, 'username'));
                    $phones = [];
                    foreach ($users as $user) {
                        if (!in_array($user['phonenumber'], $phones)) {
                            if ($via == 'sms') {
                                Message::sendSMS($user['phonenumber'], Lang::T("Your username for") . ' ' . $config['CompanyName'] . "\n" . $usernames);
                            } else {
                                Message::sendWhatsapp($user['phonenumber'], Lang::T("Your username for") . ' ' . $config['CompanyName'] . "\n" . $usernames);
                            }
                            $phones[] = $user['phonenumber'];
                        }
                    }
                    Message::sendEmail(
                        $user['email'],
                        Lang::T("Your username for") . ' ' . $config['CompanyName'],
                        Lang::T("Your username for") . ' ' . $config['CompanyName'] . "\n" . $usernames
                    );
                    file_put_contents($otpPath, time());
                }
                $ui->assign('notify_t', 's');
                $ui->assign('notify', Lang::T("Usernames have been sent to your phone/Whatsapp/Email"));
                $step = 0;
            } else {
                $ui->assign('notify_t', 'e');
                $ui->assign('notify', Lang::T("No data found"));
            }
        }
    }
}

// delete old files
$pth = $CACHE_PATH . File::pathFixer('/forgot/');
$fs = scandir($pth);
foreach ($fs as $file) {
    if(is_file($pth.$file) && time() - filemtime($pth.$file) > 3600) {
        unlink($pth.$file);
    }
}

$ui->assign('step', $step);
$ui->assign('_title', Lang::T('Forgot Password'));
$ui->display('customer/forgot.tpl');
