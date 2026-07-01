(function () {
  'use strict';

  function fillChildSelect(childSelect, tree, parentId, placeholder) {
    if (!childSelect) {
      return;
    }
    childSelect.innerHTML = '';
    var blank = document.createElement('option');
    blank.value = '';
    blank.textContent = placeholder || 'Select type…';
    childSelect.appendChild(blank);
    childSelect.disabled = !parentId;

    if (!parentId || !Array.isArray(tree)) {
      return;
    }

    var group = tree.find(function (item) {
      return String(item.id) === String(parentId);
    });
    if (!group || !group.children || !group.children.length) {
      return;
    }

    group.children.forEach(function (child) {
      var opt = document.createElement('option');
      opt.value = child.name;
      opt.textContent = child.name;
      childSelect.appendChild(opt);
    });
    childSelect.disabled = false;
  }

  function initEventTypeCascade(tree, parentId, childId) {
    var parentSelect = document.getElementById(parentId || 'event_type_parent');
    var childSelect = document.getElementById(childId || 'event_type');
    if (!parentSelect || !childSelect) {
      return;
    }

    parentSelect.addEventListener('change', function () {
      fillChildSelect(childSelect, tree, parentSelect.value, childSelect.getAttribute('data-placeholder') || 'Select type…');
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var tree = (window.lnkupEventTypeTree && window.lnkupEventTypeTree.tree) || [];
    initEventTypeCascade(tree, 'event_type_parent', 'event_type');
  });

  window.lnkupInitEventTypeCascade = initEventTypeCascade;
  window.lnkupFillEventTypeChildren = fillChildSelect;
})();
