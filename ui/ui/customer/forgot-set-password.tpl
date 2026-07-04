{include file="customer/header-public.tpl"}
{* CVPAP: choose-your-own-password form for the link/OTP reset flow *}

<div class="hidden-xs" style="height:100px"></div>
<form action="{Text::url('forgot&step=3')}" method="post">
    <input type="hidden" name="username" value="{$username}">
    <input type="hidden" name="otp_code" value="{$otp_code}">
    <div class="row">
        <div class="col-sm-4 col-sm-offset-4">
            <div class="panel panel-primary">
                <div class="panel-heading">{Lang::T('Change Password')}</div>
                <div class="panel-body">
                    <div class="form-group">
                        <div class="input-group">
                            <span class="input-group-addon"><i class="glyphicon glyphicon-user"></i></span>
                            <input type="text" readonly class="form-control" value="{$username}">
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="input-group">
                            <span class="input-group-addon"><i class="glyphicon glyphicon-lock"></i></span>
                            <input type="password" required class="form-control" name="password"
                                placeholder="{Lang::T('New Password')}">
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="input-group">
                            <span class="input-group-addon"><i class="glyphicon glyphicon-lock"></i></span>
                            <input type="password" required class="form-control" name="cpassword"
                                placeholder="{Lang::T('Confirm New Password')}">
                        </div>
                    </div>
                    <div class="form-group">
                        <button class="btn btn-primary btn-block" type="submit">{Lang::T('Save Changes')}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

{include file="customer/footer-public.tpl"}
