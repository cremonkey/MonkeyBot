<section class="section">
  <div class="section-header">
    <h1><i class="fas fa-feather-alt"></i> <?php echo $page_title; ?></h1>
  </div>
  <div class="section-body">
    <div class="row">
      <div class="col-lg-5">
        <div class="card">
          <div class="card-header"><h4>Generate</h4></div>
          <div class="card-body">
            <div class="form-group">
              <label>Content Type</label>
              <select id="cw_type" class="form-control">
                <option value="social_post">Social Media Post</option>
                <option value="ad_copy">Ad Copy</option>
                <option value="product_description">Product Description</option>
                <option value="email_campaign">Email Campaign</option>
                <option value="comment_reply">Comment Reply</option>
              </select>
            </div>
            <div class="form-group">
              <label>Topic / Brief</label>
              <textarea id="cw_topic" class="form-control" rows="4" placeholder="e.g. New summer collection, 20% off this weekend"></textarea>
            </div>
            <div class="row">
              <div class="col-6 form-group">
                <label>Tone</label>
                <select id="cw_tone" class="form-control">
                  <option value="professional">Professional</option>
                  <option value="friendly">Friendly</option>
                  <option value="funny">Funny</option>
                  <option value="urgent">Urgent</option>
                </select>
              </div>
              <div class="col-6 form-group">
                <label>Language</label>
                <input id="cw_lang" class="form-control" value="Auto" placeholder="Auto / Arabic / English">
              </div>
              <div class="col-6 form-group">
                <label>Length</label>
                <select id="cw_length" class="form-control">
                  <option value="short">Short</option>
                  <option value="medium" selected>Medium</option>
                  <option value="long">Long</option>
                </select>
              </div>
              <div class="col-6 form-group">
                <label>Variants</label>
                <select id="cw_count" class="form-control">
                  <option>1</option><option>2</option><option>3</option>
                </select>
              </div>
            </div>
            <button id="cw_generate" class="btn btn-primary btn-lg btn-block"><i class="fas fa-magic"></i> Generate</button>
          </div>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="card">
          <div class="card-header"><h4>Results</h4></div>
          <div class="card-body" id="cw_results"><p class="text-muted">Generated content will appear here.</p></div>
        </div>
        <div class="card">
          <div class="card-header"><h4>History</h4></div>
          <div class="card-body">
            <table class="table table-sm" id="cw_history"><thead><tr><th>Type</th><th>Brief</th><th>When</th><th></th></tr></thead><tbody></tbody></table>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<script>
var CW_TOKEN = "<?php echo $this->session->userdata('csrf_token_session'); ?>";
var CW_BASE = "<?php echo base_url(); ?>";
function cwEsc(s){return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function cwLoadHistory(){
  $.getJSON(CW_BASE+'ai_content_writer/history_data', function(r){
    var tb=''; (r.data||[]).forEach(function(row){
      tb+='<tr><td>'+cwEsc(row.content_type)+'</td><td>'+cwEsc((row.prompt_input||'').substring(0,40))+'</td><td>'+cwEsc(row.created_at)+'</td>'+
          '<td><button class="btn btn-sm btn-outline-secondary cw-copy" data-c="'+encodeURIComponent(row.generated_content)+'">Copy</button> '+
          '<button class="btn btn-sm btn-outline-danger cw-del" data-id="'+row.id+'">×</button></td></tr>';
    });
    $('#cw_history tbody').html(tb);
  });
}
$(function(){
  $('#cw_generate').click(function(){
    var btn=$(this); btn.prop('disabled',true).html('<i class="fas fa-spinner fa-spin"></i> Generating...');
    $('#cw_results').html('<p class="text-muted">Working...</p>');
    $.post(CW_BASE+'ai_content_writer/generate', {
      csrf_token: CW_TOKEN, content_type:$('#cw_type').val(), topic:$('#cw_topic').val(),
      tone:$('#cw_tone').val(), language:$('#cw_lang').val(), length:$('#cw_length').val(), count:$('#cw_count').val()
    }, function(r){
      if(r.status=='1'){
        var h=''; r.results.forEach(function(t){ h+='<div class="border rounded p-3 mb-2"><pre style="white-space:pre-wrap;font-family:inherit;margin:0">'+cwEsc(t)+'</pre><button class="btn btn-sm btn-outline-primary mt-2 cw-copy" data-c="'+encodeURIComponent(t)+'">Copy</button></div>'; });
        $('#cw_results').html(h); cwLoadHistory();
      } else { $('#cw_results').html('<div class="alert alert-warning">'+cwEsc(r.message)+'</div>'); }
    },'json').fail(function(){ $('#cw_results').html('<div class="alert alert-danger">Request failed.</div>'); })
    .always(function(){ btn.prop('disabled',false).html('<i class="fas fa-magic"></i> Generate'); });
  });
  $(document).on('click','.cw-copy',function(){ var t=decodeURIComponent($(this).data('c')); navigator.clipboard.writeText(t); $(this).text('Copied!'); });
  $(document).on('click','.cw-del',function(){ var id=$(this).data('id'); $.post(CW_BASE+'ai_content_writer/delete_history',{csrf_token:CW_TOKEN,id:id},function(){cwLoadHistory();},'json'); });
  cwLoadHistory();
});
</script>
