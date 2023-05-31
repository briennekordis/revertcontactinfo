CRM.$(function ($) {
  $(document).on('click', '.revertOne', function () {
    entity = this.getAttribute('entity');
    url = document.URL;
    params = new URLSearchParams(url);
    contact_id = params.get('cid');
    executeRevert(entity, contact_id);
  });

});

function executeRevert(entity, contact_id) {
  CRM.api3('ContactInfo', 'revertdata', {
    "contact_id": contact_id,
    "entity": entity
  })
  .done(function (result) {
    if (result.is_error) {
      alert("Error: this contact's " + entity + " was not reverted.");
    }
    else {
      alert("Success: this contact's " + entity +" was reverted.");
    }
  });
}
