Workflow & notes (how stages/disbursements work)

When farmer creates a loan application they define required amounts for 3 stages.

Initially the loan_applications.status = 'pending' and loan_stages.status = 'pending'.

The agent (outside UI provided here) reviews the application overall and can mark it approved/rejected. (You can implement a button in agent dashboard to change loan_applications.status).

When the agent disburses funds for a stage (not implemented as a real payment here), call disburse_stage($stage_id, $amount) — that sets disbursed_amount and status becomes 'awaiting_proof'.

Farmer uploads proof (images/videos) via handle_stage_upload which sets stage status='under_review'.

Agent reviews proofs and calls approve_stage_by_agent(..., 'approved') which sets stage status 'approved' and advances application current_stage. If the approved stage was the last, application becomes 'completed'. If agent rejects, stage becomes 'rejected'.

You can add triggers that upon agent approval the system auto-disburses the next stage amount (if desired) or queue for manual disbursement.



File storage & security

Files are stored in storage/uploads/app_{appId}/stage_{stageId}/. In production, use cloud storage (S3) or protected storage with signed URLs.

Validate file types and sizes (we allowed common images and mp4-like videos).

Serve uploads either via a PHP script that enforces auth or store outside webroot and stream with permission checks.


