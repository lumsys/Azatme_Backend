<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\GroupRequest;
use App\Http\Requests\userGroupRequest;
use App\Expense;
use Illuminate\Support\Str;
use Auth;
use App\User;
use Mail;
use Carbon\Carbon;
use App\Mail\KontributMail;
use App\UserGroup;
use App\GroupWithdrawal;
use App\Jobs\ProcessBulkExcel;
use Illuminate\Http\Response;
use App\Helper\Reply;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Exception\GuzzleException;
use Datetime;
use App\Exports\ExpenseExport;
use Excel;
use Illuminate\Support\Facades\Log;
use App\Mail\SendUserInviteMail;
use App\Setting;
use DB;
use App\Services\PaythruService;


class GroupController extends Controller
{
    //


    public $paythruService;

public function __construct(PaythruService $paythruService)
  {
      $this->paythruService = $paythruService;
  }

public function createGroup(Request $request)
    {

    $expense = Expense::create([
        'name'=> $request->name,
        'description' => $request->description,
        'uique_code'=> Str::random(10),
        'amount' => $request->amount,
        'user_id' => Auth::user()->id, 
        ]);
        
        return response()->json($expense);
        
        }
        
        public function updateGroup(Request $request, $id)
    {
    $user = Auth::user()->id;
    $get = Expense::where('id', $id)->first();
    $now = $get->user_id;
   // return $user;
    if($now != $user)
    {
         return response()->json(['You dont have edit right over this Kontribute'], 422);
    
        
    }else{
        $update = Expense::find($id);
        $update->update($request->all());
        return response()->json($update);   
}
}
        
        
public function inviteUsersToGroup(Request $request, $groupId)
        {               
        
        $group = expense::where('id', $groupId)->whereNull('category_id')->first();
        
        //return $group->id;
        
        
        $input['email'] = $request->input('email');
     
       $ProductId = env('PayThru_kontribute_productid');
       $current_timestamp= now();
       $timestamp = strtotime($current_timestamp);
       $secret = env('PayThru_App_Secret');
       $hash = hash('sha512', $timestamp . $secret);
       $amt = $group->amount;
       $hashSign = hash('sha512', $amt . $secret);
       $PayThru_AppId = env('PayThru_ApplicationId');
       $prodUrl = env('PayThru_Base_Live_Url');

      $emails = $request->email;
      if($group)
        {
      if($emails)
      {
      $emailArray = (explode(';', $emails));
      $count = count($emailArray);
    // return response()->json($emailArray);
      
      $payers = [];
      $totalpayable = 0;
      
      foreach ($emailArray as $key => $em) {
          //process each user here as each iteration gives you each email
          $user = User::where('email', $em)->first();
          
          //return $em;
        $payable = 0;
  
        if($request['split_method_id'] == 1)
        {
            $payable = $group->amount;
        } elseif($request['split_method_id'] == 2)
        {
          if(isset($request->percentage))
          {
            $payable = $group->amount*$request->percentage/100;
          }elseif(isset($request->percentage_per_user))
          {
            $ppu = json_decode($request->percentage_per_user);
            $payable = $ppu->$em*$group->amount/100;
          }
        }elseif($request['split_method_id'] == 3)
        {
         $payable = round(($group->amount / $count), 2);
            if ($key == $count - 1) {
        $payable = $group->amount - (round($payable, 2) * ($count - 1));
        }
        }
// return $payable;
        $paylink_expiration_time = Carbon::now()->addMinutes(15);

          $info = userGroup::create([
            'reference_id' => Auth::user()->id,
            'group_id' => $group->id,
            'name' => $group->name,
            'uique_code' => $group->uique_code,
            'email' => $em,
            'description' => $group['description'],
            'split_method_id' => $request['split_method_id'],
            'amount_payable' => $payable,
            'actualAmount' => $group->amount,
            'linkExpireDateTime'=> $paylink_expiration_time,
            'bankName' => $request['bankName'],
            'account_name' => $request['account_name'],
            'bankCode' => $request['bankCode'],
            'account_number' => $request['account_number'],
          ]);
      
         $payers[] =  ["payerEmail" => $em, "paymentAmount" => $info->amount_payable];
         $totalpayable = $totalpayable + $info->amount_payable;
         $paylink_expiration_time = Carbon::now()->addMinutes(15);
      }

      $token = $this->paythruService->handle();

      // Send payment request to paythru  
      $data = [
        'amount' => $group->amount,
        'productId' => $ProductId,
        'transactionReference' => time().$group->id,
        'paymentDescription' => $group->description,
        'paymentType' => 1,
        'sign' => $hashSign,
        'expireDateTime'=> $paylink_expiration_time,
        'displaySummary' => false,
        'splitPayInfo' => [
            'inviteSome' => false,
            'payers' => $payers
          ]
        ];
              
      //return $token;
    $url = $prodUrl;
    $urls = $url.'/transaction/create';
    //return $urls;
     
     
    $response = Http::withHeaders([
        'Content-Type' => 'application/json',
        'Authorization' => $token,
  ])->post($urls, $data);
      if($response->failed())
      {
        return false;
      }else{
        $transaction = json_decode($response->body(), true);
       //return $transaction;
        $splitResult = $transaction['splitPayResult']['result'];
        foreach($splitResult as $key => $slip)
        {
          Mail::to($slip['receipient'])->send(new KontributMail($slip));
          $paylink = $slip['paylink'];
       // return $paylink;
            if($paylink)
            {
              $getLastString = (explode('/', $paylink));
              $now = end($getLastString);
              //return $now;
        $userGroupReference = userGroup::where(['email' => $slip['receipient'], 'group_id' => $group->id, 'reference_id' => Auth::user()->id])->update([
            'paymentReference' => $now,
        ]);
      }
        }
      }
      return response()->json($transaction);
      
    }
         }else{
              return response([
                'message' => "Id doesn't belong to this transaction category"
            ], 401);
             
         }
        }  
        
    
    public function UpdateTransactionGroupRequest(Request $request, $transactionId)
    {
      $transaction = userGroup::findOrFail($transactionId);
      //return $transaction;
      if($transaction->status == null)
      {
        $updateTransaction = $request->all();
        $update = userGroup::where('id', $transactionId)->update([
          'email' => $request->email,
      ]);
      return response([
                'message' => 'successful'
            ], 200);
      }else{
          return response([
                'message' => 'You cannot edit this transaction anymore'
            ], 422);
      }
      
    }
         
         
    public function webhookGroupResponse(Request $request)
  { 
        $response = $request->all();
        $dataEncode = json_encode($response);
        $data = json_decode($dataEncode);
        Log::info("webhook-data" . json_encode($data));
        
        if($data->transactionDetails->status == 'Successful'){
         // return "good";
        $userExpense = userGroup::where('paymentReference', $data->transactionDetails->paymentReference)->update([
            'payThruReference' => $data->transactionDetails->payThruReference,
            'fiName' => $data->transactionDetails->fiName,
            'status' => $data->transactionDetails->status,
            'amount' => $data->transactionDetails->amount,
            'responseCode' => $data->transactionDetails->responseCode,
            'paymentMethod' => $data->transactionDetails->paymentMethod,
            'commission' => $data->transactionDetails->commission,
            'residualAmount' => $data->transactionDetails->residualAmount,
            'resultCode' => $data->transactionDetails->resultCode,
            'responseDescription' => $data->transactionDetails->responseDescription,
        ]);
          Log::info("done for Kontribute");
          
          http_response_code(200);

        }else
       return response([
                'message' => 'payment not successfully updated'
            ], 401);
    }

public function AzatGroupCollection(Request $request, $transactionId)
    {
      $current_timestamp= now();
     // return $current_timestamp;
      $timestamp = strtotime($current_timestamp);
      $secret = env('PayThru_App_Secret');
      $productId = env('PayThru_kontribute_productid');
      //return $productId;
      $hash = hash('sha512', $timestamp . $secret);
      //return $hash;
      $AppId = env('PayThru_ApplicationId');
      $prodUrl = env('PayThru_Base_Live_Url');
    
    
      //return 
    
      $groupAmount = userGroup::where('reference_id', Auth::user()->id)->where('group_id', $transactionId)->whereNotNull('amount_payable')->first();
      //return $groupAmount;
      
      $amount = $groupAmount->amount_payable;
       // return $amount;

      $groupWithdrawal = new GroupWithdrawal([
        'account_number' => $request->account_number,
        'description' => $request->description,
        'group_id' => $groupAmount->id,
        'beneficiary_id' => Auth::user()->id,
        'amount' => $request->amount,
        'bank' => $request->bank
        ]);
        
        $getUserKontributeTransactions = userGroup::where('reference_id', Auth::user()->id)->sum('residualAmount');
        
        if(($request->amount) > $amount)
        {
            if(($request->amount) > $getUserKontributeTransactions)
            {
                return response([
            'message' => 'You dont not have sufficient amount in your Kontribute'
        ], 403);
            }else{
          return response([
            'message' => 'Please enter correct Kontribute amount'
        ], 403);
        }
        }
        
        
    $groupWithdrawal->save();
    $acct = $request->account_number;
    $getBankReferenceId = Bank::where('user_id', Auth::user()->id)->where('account_number', $acct)->first();
   //return $getBankReferenceId;
   
    $beneficiaryReferenceId = $getBankReferenceId->referenceId;

    $token = $this->paythruService->handle();

      $data = [
            'productId' => $productId,
            'amount' => $amount,
            'beneficiary' => [
            'nameEnquiryReference' => $beneficiaryReferenceId
            ],
        ];
        
  
      //return $token;
    $url = $prodUrl;
    $urls = $url.'/transaction/settlement';
    //return $urls;

         $response = Http::withHeaders([
        'Content-Type' => 'application/json',
        'Authorization' => $token,
      ])->post($urls, $data );
      if($response->failed())
      {
        return false;
      }else{
        $collection = json_decode($response->body(), true);
        return $collection;
    
  }
}
        
        
        public function countAllGroupsPerUser()
        {
        $getAuthUser = Auth::user();
        $getUserGroups = UserGroup::where('reference_id', $getAuthUser->id)->count();
        return response()->json($getUserGroups);
        }
        
        public function getAllGroupsPerUser()
        {
        $getAuthUser = Auth::user();
        $countUserGroups = UserGroup::where('reference_id', $getAuthUser->id)->get();
        return response()->json($countUserGroups);
        
        }
        
        
        public function getUserGroup()
    {
            $pageNumber = 50;
            $getAuthUser = Auth::user();
            //return Auth::user()->email;
            $getUserGroupExpense = Expense::where('user_id', $getAuthUser->id)->whereNull('subcategory_id')->paginate($pageNumber);
            $getUserGroupAddedTransactions = userGroup::where('email', $getAuthUser->email)->paginate($pageNumber);
           
            
            return response()->json([
                'getAuthUserGroupsCreated' => $getUserGroupExpense,
                'getGroupsInvitedTo' => $getUserGroupAddedTransactions,
            ]);
}

        public function getRandomUserGroup($email)
{

        $getUserGroup = userGroup::where('reference_id', Auth::user()->id)->where('email', $email)->first();
        return response()->json($getUserGroup);

}

        public function getAllMemebersOfAGroup($groupId)
{
    
        $getUserGroup = userGroup::where('reference_id', Auth::user()->id)->where('group_id', $groupId)->select('email')->get();
        return response()->json($getUserGroup);

}


public function getUserAmountsPaidPerGroup(Request $request, $groupId)
    {
        $UserAmountsPaid = userGroup::where('reference_id', Auth::user()->id)->where('group_id', $groupId)->get();
        return response()->json($UserAmountsPaid);
    }



        public function getOneGroupPerUser($id)
        {
// $getAuthUser = Auth::user();
        $get = userGroup::find($id);
        $getUserGroup = UserGroup::where('group_id', $get)->first();
         return response()->json($getUserGroup);

        }
        

        public function deleteInvitedGroupUser($user_id) 
{

        $deleteInvitedExpenseUser = UserGroup::findOrFail($user_id);
        $getDeleteUserGroup = userGroup::where('_id', Auth::user()->id)->where('user_id', $deleteInvitedExpenseUser)->first();
        if($getDeleteUserGroup)
         $getDeleteUserGroup->delete(); 
        // return "done";
        else
        return response()->json(null); 
}

        
        public function deleteGroup($id) 
        {
        //$user = Auth()->user();
        $deleteExpense = expense::findOrFail($id);
        $getDeletedExpense = expense::where('user_id', Auth::user()->id)->where('id', $deleteExpense);
        if($deleteExpense)
        //$userDelete = Expense::where('user', $user)
        $deleteExpense->delete(); 
        else
        return response()->json(null); 
        }
        
        
         public function groupSettlementWebhookResponse(Request $request)
   {
       
        $productId = env('PayThru_business_productid');
       
        $response = $request->all();
        $dataEncode = json_encode($response);
        $data = json_decode($dataEncode);
        if($data->notificationType == 2){
         // return "good";
        $updateGroupWithdrawal = GroupWithdrawal::where(['transactionReferences'=> $data->transactionDetails->transactionReferences, $productId => $data->transactionDetails->productId])->update([
            'paymentAmount' => $data->transactionDetails->paymentAmount,
            'recordDateTime' => $data->transactionDetails->recordDateTime,
        ]);
          Log::info("payback settlement done");
          http_response_code(200);

        }else
       return response([
                'message' => 'data does not exists'
            ], 401);
    }
        
        

}
