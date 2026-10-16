<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case Activation = 'activation';
    case Login = 'login';
    case StepUpRelease = 'step_up_release';
}
