<?php

namespace App\Http\Controllers;

use App\Mail\SupportMessageMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class SupportController extends Controller
{
    public function send(Request $r)
    {
        $data = $r->validate([
            'name'     => ['required','string','max:150'],
            'email'    => ['required','email','max:255'],
            'phone'    => ['nullable','string','max:32'],
            'category' => ['required','string', Rule::in([
                'General Inquiry','Technical Support','Payment Issues','Lost & Found',
                'Schedule Information','Accessibility','Complaints','Suggestions','Emergency Report'
            ])],
            'subject'  => ['required','string','max:200'],
            'message'  => ['required','string','min:10','max:5000'],
        ]);

        
        $to = env('SUPPORT_TO_EMAIL', 'ayoub.nadjem09@gmail.com');

        
        Mail::to($to)->send(new SupportMessageMail($data));

        return response()->json(['ok' => true]);
    }
}
?>
